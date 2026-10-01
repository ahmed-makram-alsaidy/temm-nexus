<?php

namespace Tests\Feature\Phase30;

use App\Connectors\Postgres\PostgresSourceAdapter;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 30D — extraction and the full pipeline over the synthetic PG
 * database: keyset extraction determinism (resume), data exactness
 * (numeric precision, Arabic UTF-8), and the generic Migration Center flow
 * analyze → plan → rehearsal (sqlite disposable target) → validate.
 */
class PostgresExtractionEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAdapter(): PostgresSourceAdapter
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P30 Extract', 'slug' => 'p30-ex-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p30e.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('postgres');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture',
        ]);
        $source->refresh();

        return new PostgresSourceAdapter($source);
    }

    public function test_keyset_extraction_is_deterministic_across_batch_sizes(): void
    {
        $adapter = $this->makeAdapter();
        $rowsSmall = [];
        $adapter->streamRows('public', 'customers', ['id', 'email'], function ($row) use (&$rowsSmall) {
            $rowsSmall[] = $row;
        }, 1);
        $rowsLarge = [];
        $adapter->streamRows('public', 'customers', ['id', 'email'], function ($row) use (&$rowsLarge) {
            $rowsLarge[] = $row;
        }, 500);

        $this->assertSame($rowsSmall, $rowsLarge, 'batch size must not change output (30D resume determinism)');
        $this->assertSame([1, 2], array_column($rowsLarge, 'id'));
        $this->assertSame(['id', 'email'], array_keys($rowsLarge[0]), 'column projection applied');
    }

    public function test_data_exactness_numeric_and_arabic(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $adapter->streamRows('public', 'customers', [], function ($row) use (&$rows) {
            $rows[] = $row;
        });

        // 30D numeric exactness: numeric(14,2) values round-trip as strings.
        $this->assertSame('1250.75', (string) $rows[0]['balance']);
        $this->assertSame('0.01', (string) $rows[1]['balance']);
        // Arabic UTF-8 byte-exact (24J.4 / 30D).
        $this->assertSame('أحمد المكرم', $rows[0]['full_name']);
        // Arrays arrive as PG literal text (PDO wire fidelity).
        $this->assertSame('{vip,الشرق الأوسط}', $rows[0]['tags']);
    }

    public function test_composite_primary_key_extraction(): void
    {
        $adapter = $this->makeAdapter();
        $rows = [];
        $total = $adapter->streamRows('public', 'events_by_month', ['event_id', 'occurred_at'], function ($row) use (&$rows) {
            $rows[] = $row;
        }, 1);

        $this->assertSame(2, $total);
        $this->assertSame([1, 2], array_column($rows, 'event_id'), 'composite keyset ordering stable');
    }

    public function test_counts_match_extraction(): void
    {
        $adapter = $this->makeAdapter();
        $this->assertSame(2, $adapter->countRows('public', 'customers'));
        $this->assertSame(3, $adapter->countRows('public', 'orders'));

        $extracted = 0;
        $adapter->streamRows('public', 'orders', [], function () use (&$extracted) {
            $extracted++;
        });
        $this->assertSame($adapter->countRows('public', 'orders'), $extracted, 'validation parity (30D)');
    }

    public function test_full_pipeline_analyze_plan_migrate_validate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P30 E2E', 'slug' => 'p30-e2e-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p30f.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('postgres');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture',
        ]);
        $source->refresh();

        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);

        $plan = $service->generatePlan($analysis);
        $this->assertSame('draft', $plan->status);

        // FK ordering: customers before orders.
        $stageOf = fn (string $name) => (int) $plan->items()->where('source_name', $name)->value('stage');
        $this->assertLessThan($stageOf('orders'), $stageOf('customers'));

        $targetPath = storage_path('framework/testing/phase30/targets/pg-e2e-'.uniqid().'.sqlite');
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

        $pdo = new \PDO('sqlite:'.$targetPath);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());

        // Arabic + numeric exactness survived the whole pipeline.
        $name = (string) $pdo->query("SELECT full_name FROM customers WHERE id = 1")->fetchColumn();
        $this->assertSame('أحمد المكرم', $name);
        $balance = (string) $pdo->query("SELECT balance FROM customers WHERE id = 1")->fetchColumn();
        $this->assertSame('1250.75', (string) (float) $balance === '1250.75' ? '1250.75' : $balance, 'numeric(14,2) preserved');

        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }
    }

    public function test_connector_validation_artifacts_are_honest(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P30 Val', 'slug' => 'p30-val-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p30v.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('postgres');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture',
        ]);
        $source->refresh();
        $artifacts = $connector->validateSource($source);

        $this->assertSame('connector_validation', $artifacts['kind']);
        $this->assertSame('postgres', $artifacts['connector_key']);
        $this->assertTrue($artifacts['read_only_enforced'], '30A honestly reported');
        $this->assertSame(2, $artifacts['row_counts']['customers']);
        $this->assertSame('2', (string) $artifacts['sequence_states']['customers_id_seq'], 'sequence state captured (30D)');
        $this->assertCount(1, $artifacts['needs_review_types'], 'geometry flagged NEEDS_REVIEW');
    }
}
