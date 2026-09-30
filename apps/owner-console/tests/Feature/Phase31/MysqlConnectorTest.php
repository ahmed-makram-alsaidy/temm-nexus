<?php

namespace Tests\Feature\Phase31;

use App\Connectors\Mysql\MysqlConnector;
use App\Connectors\Mysql\MysqlSourceAdapter;
use App\Connectors\Mysql\MysqlTypeMapper;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 31 — MySQL/MariaDB connector: contract battery, unsigned-safe type
 * mapping (31B/31C), AUTO_INCREMENT state (31D), charset review (31E),
 * extraction and the full pipeline over the synthetic fixture.
 */
class MysqlConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function makeAdapter(): MysqlSourceAdapter
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P31', 'slug' => 'p31-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p31.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('mysql');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'port' => '3306', 'database' => 'synthetic_mysql', 'username' => 'reader',
            'transport' => 'fixture',
        ]);
        $source->refresh();

        return new MysqlSourceAdapter($source);
    }

    public function test_contract_battery_passes(): void
    {
        $connector = new MysqlConnector;
        $results = ConnectorContractTester::run($connector);
        $summary = ConnectorContractTester::summarize($results);
        $this->assertSame('PASS', $summary['status'], $summary['detail']);
    }

    public function test_unsigned_widening_is_deterministic_and_safe(): void
    {
        // 31B/31C — every unsigned variant widens UP, never overflows.
        $cases = [
            'int unsigned' => 'int8',
            'tinyint unsigned' => 'int2',
            'smallint unsigned' => 'int4',
            'mediumint unsigned' => 'int4',
            'bigint unsigned' => 'numeric(20,0)',
            'bigint' => 'int8',
            'int' => 'int4',
        ];
        foreach ($cases as $mysqlType => $expected) {
            $this->assertSame($expected, MysqlTypeMapper::normalize($mysqlType)['type'], $mysqlType);
        }
        // 31C — boundary values survive as exact strings (fixture rows carry them).
        foreach (MysqlTypeMapper::UNSIGNED_BOUNDARIES as $mysqlType => $boundary) {
            $this->assertGreaterThan(0, (int) $boundary, $mysqlType);
        }
    }

    public function test_type_mapping_covers_the_31b_surface(): void
    {
        $cases = [
            'decimal(14,2)' => 'numeric(14,2)',
            'varchar(180)' => 'varchar(180)',
            'text' => 'text',
            'blob' => 'bytea',
            'json' => 'jsonb',
            'datetime' => 'timestamp',
            'timestamp' => 'timestamptz',
            'date' => 'date',
            'year' => 'int2',
            'double' => 'float8',
            'float' => 'float4',
            'tinyint(1)' => 'boolean',
            'bit(1)' => 'boolean',
        ];
        foreach ($cases as $mysqlType => $expected) {
            $this->assertSame($expected, MysqlTypeMapper::normalize($mysqlType)['type'], $mysqlType);
        }
        // SET → review (semantics differ); BIT(n>1) → review.
        $this->assertTrue(MysqlTypeMapper::normalize("set('a','b')")['needs_review']);
        $this->assertTrue(MysqlTypeMapper::normalize('bit(8)')['needs_review']);
        // ENUM members extracted verbatim.
        $this->assertSame(['bronze', 'silver', 'gold'], MysqlTypeMapper::enumValues("enum('bronze','silver','gold')"));
    }

    public function test_inventory_maps_types_flags_charsets_and_state(): void
    {
        $adapter = $this->makeAdapter();
        $inventory = $adapter->inventory();
        $mysql = $inventory['mysql'];

        $customers = collect($inventory['tables'])->firstWhere('name', 'customers');
        $columns = collect($customers['columns'])->keyBy('name');
        $this->assertSame('int8', $columns['id']['type'], 'int unsigned → int8');
        $this->assertSame('int unsigned', $columns['id']['source_type'], 'COLUMN_TYPE preserved verbatim');
        $this->assertSame('numeric(14,2)', $columns['balance']['type']);
        $this->assertSame('boolean', $columns['is_active']['type'], 'tinyint(1) → boolean');
        $this->assertSame('int8', $columns['flags']['type']);
        $this->assertSame('numeric(20,0)', $columns['big_counter']['type'], 'bigint unsigned → numeric(20,0) — no overflow possible');
        $this->assertSame('jsonb', $columns['profile']['type']);

        // 31D — AUTO_INCREMENT state captured.
        $this->assertSame('3', (string) $mysql['auto_increment']['customers']);
        $this->assertSame('1004', (string) $mysql['auto_increment']['orders']);

        // 31E — latin1 column flagged for transcoding review.
        $flagged = collect($mysql['transcoding_review'])->first(fn ($entry) => $entry['table'] === 'customers' && $entry['column'] === 'legacy_note');
        $this->assertNotNull($flagged, 'non-UTF-8 charset flagged');
        $this->assertSame('latin1', $flagged['charset']);

        // Generated column preserved with its expression, no copyable default.
        $orders = collect($inventory['tables'])->firstWhere('name', 'orders');
        $orderColumns = collect($orders['columns'])->keyBy('name');
        $this->assertTrue((bool) $orderColumns['total_with_tax']['is_generated']);
        $this->assertNull($orderColumns['total_with_tax']['default']);
    }

    public function test_enum_columns_become_pg_enums(): void
    {
        $adapter = $this->makeAdapter();
        $inventory = $adapter->inventory();

        $levelEnum = collect($inventory['enums'])->firstWhere('name', 'customers__level');
        $this->assertSame(['bronze', 'silver', 'gold'], $levelEnum['values'], '31B — ENUM members preserved via ensureEnum');
        $customers = collect($inventory['tables'])->firstWhere('name', 'customers');
        $columns = collect($customers['columns'])->keyBy('name');
        $this->assertSame('customers__level', $columns['level']['type']);
    }

    public function test_views_triggers_routines_events_inventoried(): void
    {
        $inventory = $this->makeAdapter()->inventory();
        $this->assertSame('paid_orders', $inventory['views'][0]['name']);
        $this->assertSame('orders_before_insert', $inventory['triggers'][0]['trigger_name']);
        $this->assertSame('add_two', $inventory['functions'][0]['name'], 'functions separated from procedures');
        // 31A — scheduled events land in the cron section (schedule metadata).
        $this->assertSame('nightly_cleanup', $inventory['cron'][0]['name']);
        $this->assertSame('every 1 DAY', $inventory['cron'][0]['schedule']);
    }

    public function test_extraction_is_deterministic_and_exact(): void
    {
        $adapter = $this->makeAdapter();
        $rowsSmall = [];
        $adapter->streamRows('synthetic_mysql', 'customers', ['id', 'full_name', 'big_counter'], function ($row) use (&$rowsSmall) {
            $rowsSmall[] = $row;
        }, 1);
        $rowsLarge = [];
        $total = $adapter->streamRows('synthetic_mysql', 'customers', ['id', 'full_name', 'big_counter'], function ($row) use (&$rowsLarge) {
            $rowsLarge[] = $row;
        }, 500);

        $this->assertSame($rowsSmall, $rowsLarge, '31D — batch size must not change output');
        $this->assertSame(2, $total);
        // 31C — unsigned bigint boundary survives exactly.
        $this->assertSame('18446744073709551615', (string) $rowsLarge[0]['big_counter']);
        // 31F — utf8mb4 Arabic byte-exact.
        $this->assertSame('أحمد المكرم', $rowsLarge[0]['full_name']);
        $this->assertSame(2, $adapter->countRows('synthetic_mysql', 'customers'));
    }

    public function test_full_pipeline_analyze_plan_migrate_validate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P31 E2E', 'slug' => 'p31-e2e-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p31e.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('mysql');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'port' => '3306', 'database' => 'synthetic_mysql',
            'username' => 'reader', 'transport' => 'fixture',
        ]);
        $source->refresh();

        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);

        $plan = $service->generatePlan($analysis);
        $this->assertSame('draft', $plan->status);
        $stageOf = fn (string $name) => (int) $plan->items()->where('source_name', $name)->value('stage');
        $this->assertLessThan($stageOf('orders'), $stageOf('customers'), 'FK ordering across MySQL source');

        $targetPath = storage_path('framework/testing/phase31/targets/mysql-e2e-'.uniqid().'.sqlite');
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
        $this->assertSame('أحمد المكرم', (string) $pdo->query("SELECT full_name FROM customers WHERE id = 1")->fetchColumn());

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
            'name' => 'P31 Val', 'slug' => 'p31-val-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p31v.test', 'api_version' => 'v1',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('mysql');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'port' => '3306', 'database' => 'synthetic_mysql',
            'username' => 'reader', 'transport' => 'fixture',
        ]);
        $source->refresh();
        $artifacts = $connector->validateSource($source);

        $this->assertSame('connector_validation', $artifacts['kind']);
        $this->assertSame('mysql', $artifacts['connector_key']);
        $this->assertTrue($artifacts['read_only_enforced']);
        $this->assertSame('3', (string) $artifacts['auto_increment_states']['customers'], '31D state captured');
        $this->assertNotEmpty($artifacts['unsigned_widenings'], '31C widening decisions recorded');
        $this->assertNotEmpty($artifacts['transcoding_review'], '31E flags surfaced');
    }
}
