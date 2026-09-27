<?php

namespace Tests\Feature\Phase27;

use App\Connectors\ExampleJson\ExampleJsonSourceAdapter;
use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27N — the example (non-Supabase) connector works END TO END through
 * the generic Migration Center: register → configure → analyze → plan →
 * migrate (sqlite disposable target) → validate. 27P.2: PASS proves the core
 * no longer depends on Supabase for the migration pipeline.
 */
class ExampleConnectorEndToEndTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
        $this->actingAs($this->admin);
    }

    protected function makeSource(?string $dataset = null): MigrationSource
    {
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');

        return $connector->createSourceProfile($this->projectA, [], [
            'dataset_path' => $dataset ?? $this->buildJsonDataset(),
            'display_name' => 'Example dataset source',
        ]);
    }

    public function test_schema_inference_finds_fields_types_and_relationships(): void
    {
        $source = $this->makeSource();
        $adapter = new ExampleJsonSourceAdapter($source);
        $inventory = $adapter->inventory();

        $users = collect($inventory['tables'])->firstWhere('name', 'users');
        $columns = collect($users['columns'])->keyBy('name');
        // Types are normalized to the engine's PostgreSQL-style vocabulary.
        $this->assertSame('int8', $columns['id']['type']);
        $this->assertSame('text', $columns['name']['type']);
        $this->assertSame('int8', $columns['age']['type']);
        $this->assertSame('boolean', $columns['is_active']['type']);
        $this->assertSame('timestamptz', $columns['created_at']['type']);
        $this->assertSame(['id'], $users['primary_key']);
        $this->assertSame(12, $users['row_estimate']);

        $items = collect($inventory['tables'])->firstWhere('name', 'order_items');
        $this->assertSame('orders', $items['foreign_keys'][0]['references_table'], 'order_id → orders.id by convention');

        $fingerprint = $adapter->fingerprint();
        $this->assertSame($fingerprint, $adapter->fingerprint(), 'fingerprint is deterministic');
    }

    public function test_extraction_is_batched_and_projected(): void
    {
        $source = $this->makeSource();
        $adapter = new ExampleJsonSourceAdapter($source);

        $batches = [];
        $total = $adapter->streamRows('public', 'users', ['id', 'name'], function ($row) use (&$batches) {
            $batches[] = $row;
        }, 5);

        $this->assertSame(12, $total);
        $this->assertCount(12, $batches);
        foreach ($batches as $row) {
            $this->assertSame(['id', 'name'], array_keys($row), 'column projection applied');
        }
        $this->assertSame(12, $adapter->countRows('public', 'users'));
        $this->assertSame(0, $adapter->streamAuthUsers(fn ($r) => true), 'no auth domain on this source');
    }

    public function test_full_pipeline_analyze_plan_migrate_validate_without_supabase(): void
    {
        $source = $this->makeSource();

        // analyze (connector → normalized inventory → analysis artifacts)
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);

        $counts = $analysis->counts;
        $this->assertSame(3, $counts['tables']);
        $this->assertSame(0, $counts['auth']);
        $this->assertStringNotContainsString('Supabase', json_encode($counts));

        // plan (generic engine, FK-dependency ordered)
        $plan = $service->generatePlan($analysis);
        $this->assertSame('draft', $plan->status);
        $tableItems = $plan->items()->where('source_kind', 'table')->get();
        $this->assertCount(3, $tableItems);
        // FK ordering: users and orders before order_items.
        $stages = $tableItems->pluck('stage', 'source_name');
        $this->assertTrue(
            max($stages['users'], $stages['orders']) < $stages['order_items'],
            'order_items must be planned after its FK targets'
        );

        // run (rehearsal on a disposable sqlite target)
        $targetPath = storage_path('framework/testing/phase27/targets/example-e2e-'.uniqid().'.sqlite');
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }
        $manager = new MigrationRunManager;
        $run = $manager->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => ['driver' => 'sqlite', 'path' => $targetPath, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'],
            'target_disposable' => true,
            'reset' => true,
            'target_environment_type' => 'development',
        ]);
        $manager->execute($run);
        $run = $run->fresh();
        $itemErrors = $run->items()->where('status', 'failed')->pluck('error')->all();
        $this->assertSame('completed', $run->status, 'run failure: '.json_encode($run->failure).' item errors: '.json_encode($itemErrors));

        // Records actually landed in the target.
        $pdo = new \PDO('sqlite:'.$targetPath);
        $this->assertSame(12, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(24, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $this->assertSame(36, (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());

        // validate (generic validator suite over the same plan)
        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }

        // Foreign keys preserved through the pipeline.
        $this->assertSame(12, (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM orders')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE u.id IS NULL')->fetchColumn(), 'no orphans in target');

        // dry-run never writes
        $dryTarget = storage_path('framework/testing/phase27/targets/example-dry-'.uniqid().'.sqlite');
        $dryRun = $manager->start($plan->fresh(), [
            'mode' => 'dry_run',
            'target' => ['driver' => 'sqlite', 'path' => $dryTarget, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'],
            'target_disposable' => true,
            'reset' => false,
            'target_environment_type' => 'development',
        ]);
        $manager->execute($dryRun);
        $this->assertSame('completed', $dryRun->fresh()->status);
        $this->assertFileDoesNotExist($dryTarget, 'dry run never creates the target database');
    }

    public function test_connector_validation_artifacts_report_relationship_integrity(): void
    {
        // 27B.4 — connector-provided validation artifacts.
        $source = $this->makeSource();
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');
        $artifacts = $connector->validateSource($source);

        $this->assertSame('connector_validation', $artifacts['kind']);
        $this->assertSame('example-json', $artifacts['connector_key']);
        $this->assertSame(12, $artifacts['row_counts']['users']);
        $this->assertSame(24, $artifacts['row_counts']['orders']);
        $this->assertSame([], $artifacts['foreign_key_issues'], 'consistent fixture has no FK violations');

        // Break the relationship: delete a user's row → violations appear.
        $dataset = $source->connection['dataset_path'];
        $users = json_decode(file_get_contents($dataset.'/users.json'), true);
        array_shift($users);
        file_put_contents($dataset.'/users.json', json_encode($users));
        $broken = $connector->validateSource($source);
        $this->assertNotEmpty($broken['foreign_key_issues'], 'orphaned orders detected by the connector');
    }
}
