<?php

namespace Tests\Feature\Phase24;

use App\Models\Project;
use App\Services\ControlPlane\SchemaDiffService;
use App\Services\ControlPlane\Migration\SchemaFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 24E — Schema fingerprint, diff classification, dangerous drift. */
class SchemaDiffTest extends TestCase
{
    use RefreshDatabase;

    protected function inventory(array $overrides = []): array
    {
        $base = [
            'tables' => [[
                'schema' => 'public', 'name' => 'shipments',
                'columns' => [
                    ['name' => 'id', 'type' => 'uuid', 'nullable' => false, 'default' => null],
                    ['name' => 'ref', 'type' => 'text', 'nullable' => true, 'default' => null],
                    ['name' => 'amount', 'type' => 'numeric', 'nullable' => true, 'default' => null],
                ],
                'primary_key' => ['id'],
                'foreign_keys' => [['column' => 'ref', 'references_schema' => 'public', 'references_table' => 'refs', 'references_column' => 'id']],
                'indexes' => [['name' => 'shipments_ref_idx', 'columns' => ['ref'], 'unique' => false]],
            ]],
            'views' => [['schema' => 'public', 'name' => 'v_shipments']],
            'matviews' => [],
            'enums' => [['name' => 'shipment_status', 'schema' => 'public', 'values' => ['pending', 'delivered']]],
            'functions' => [['schema' => 'public', 'name' => 'calc_total']],
            'triggers' => [['schema' => 'public', 'name' => 'shipments_audit', 'table' => 'shipments']],
            'extensions' => [['name' => 'pgcrypto', 'version' => '1.3']],
        ];

        return array_replace_recursive($base, $overrides);
    }

    public function test_fingerprint_is_deterministic_and_shape_sensitive(): void
    {
        $a = SchemaFingerprint::compute($this->inventory());
        $b = SchemaFingerprint::compute($this->inventory()); // same shape, new arrays

        $this->assertSame($a, $b, 'identical shapes must produce identical fingerprints');

        $changed = $this->inventory();
        $changed['tables'][0]['columns'][2]['type'] = 'bigint'; // type narrowing-ish change
        $this->assertNotSame($a, SchemaFingerprint::compute($changed));

        $extraTable = $this->inventory();
        $extraTable['tables'][] = ['schema' => 'public', 'name' => 'extra', 'columns' => [], 'primary_key' => [], 'foreign_keys' => [], 'indexes' => []];
        $this->assertNotSame($a, SchemaFingerprint::compute($extraTable));
    }

    public function test_fingerprint_ignores_row_counts_and_ordering(): void
    {
        $a = $this->inventory();
        $b = $this->inventory();
        $b['tables'][0]['row_estimate'] = 999999; // not part of fingerprint
        $b['tables'][0]['columns'] = array_reverse($b['tables'][0]['columns']); // column order normalized

        $this->assertSame(
            SchemaFingerprint::compute($a),
            SchemaFingerprint::compute($b)
        );
    }

    public function test_identical_schemas_diff_to_empty(): void
    {
        $rows = SchemaDiffService::diff($this->inventory(), $this->inventory());
        $this->assertSame([], $rows);
    }

    public function test_safe_add_and_dangerous_removal_classification(): void
    {
        $a = $this->inventory();
        $b = $this->inventory();

        // Safe: new nullable column + new table.
        $b['tables'][0]['columns'][] = ['name' => 'note', 'type' => 'text', 'nullable' => true, 'default' => null];
        $b['tables'][] = ['schema' => 'public', 'name' => 'new_table', 'columns' => [], 'primary_key' => [], 'foreign_keys' => [], 'indexes' => []];
        $rows = SchemaDiffService::diff($a, $b);
        $this->assertContains('SAFE', array_column($rows, 'severity'));
        $this->assertNotContains('DANGEROUS', array_column($rows, 'severity'));

        // Dangerous: column dropped.
        $c = $this->inventory();
        array_pop($c['tables'][0]['columns']); // drop 'amount'
        $rows = SchemaDiffService::diff($this->inventory(), $c);
        $this->assertContains('DANGEROUS', array_column($rows, 'severity'));

        // Dangerous: type narrowing (numeric → integer).
        $d = $this->inventory();
        $d['tables'][0]['columns'][2]['type'] = 'int4';
        $rows = SchemaDiffService::diff($this->inventory(), $d);
        $typeChanges = array_values(array_filter($rows, fn ($r) => str_contains($r['detail'], 'type changed')));
        $this->assertNotEmpty($typeChanges);
        $this->assertSame('DANGEROUS', $typeChanges[0]['severity']);

        // Dangerous: FK removed.
        $e = $this->inventory();
        $e['tables'][0]['foreign_keys'] = [];
        $rows = SchemaDiffService::diff($this->inventory(), $e);
        $this->assertContains('DANGEROUS', array_column($rows, 'severity'));

        // Dangerous: unique index removed.
        $f = $this->inventory();
        $f['tables'][0]['indexes'][0]['unique'] = false;
        $f['tables'][0]['indexes'][0]['name'] = 'other_idx';
        $g = $this->inventory();
        $g['tables'][0]['indexes'][0]['unique'] = true;
        $rows = SchemaDiffService::diff($g, $f);
        $this->assertContains('DANGEROUS', array_column($rows, 'severity'));
    }

    public function test_primary_key_change_is_dangerous(): void
    {
        $b = $this->inventory();
        $b['tables'][0]['primary_key'] = ['ref'];
        $rows = SchemaDiffService::diff($this->inventory(), $b);
        $pk = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'primary_key'));
        $this->assertNotEmpty($pk);
        $this->assertSame('DANGEROUS', $pk[0]['severity']);
    }

    public function test_has_dangerous_helper(): void
    {
        $rows = SchemaDiffService::diff($this->inventory(), $this->inventory());
        $this->assertFalse(SchemaDiffService::hasDangerous($rows));

        $c = $this->inventory();
        $c['tables'] = [];
        $this->assertTrue(SchemaDiffService::hasDangerous(SchemaDiffService::diff($this->inventory(), $c)));
    }

    public function test_snapshot_persists_and_audits(): void
    {
        $project = Project::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        $snap = SchemaDiffService::snapshot($project, null, 'live', $this->inventory(), 'test', null);

        $this->assertDatabaseHas('schema_snapshots', ['id' => $snap->id, 'source' => 'live']);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'SCHEMA_SNAPSHOT_CREATED', 'project_id' => $project->id]);
    }
}
