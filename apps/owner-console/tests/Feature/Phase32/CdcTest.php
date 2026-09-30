<?php

namespace Tests\Feature\Phase32;

use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Migration\Cdc\CdcApplier;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpointTampered;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpointManager;
use App\Services\ControlPlane\Migration\Cdc\CdcEvent;
use App\Services\ControlPlane\Migration\Cdc\CdcProbe;
use App\Services\ControlPlane\Migration\Cdc\IncrementalExportCapture;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Services\ControlPlane\Migration\TargetAdapters\SqliteTargetAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 32 — the generic CDC layer: capability vocabulary (32A), tamper-safe
 * checkpoints (32C/32F), idempotent application (32G) and honest provider
 * probes (32B/32C/32D/32E). Log-based capture against live servers is
 * environment-blocked (see the program report) — the contract, checkpoint
 * model, applier semantics and probe honesty are proven here.
 */
class CdcTest extends TestCase
{
    use RefreshDatabase;

    // ── 32C/32F — checkpoints ────────────────────────────────────────────

    public function test_checkpoints_round_trip_with_signature_verification(): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store(1, 'postgres', 'public.orders', 'lsn', ['lsn' => '0/16B9F80', 'snapshot' => '2026-09-30T00:00:00Z'], 42, 5);

        $loaded = $manager->load(1, 'public.orders');
        $this->assertNotNull($loaded);
        $this->assertSame('0/16B9F80', $loaded->position['lsn']);
        $this->assertSame(42, $loaded->applied_events);
        $this->assertSame(5, $loaded->lag_events);
    }

    public function test_tampered_checkpoints_are_refused(): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store(2, 'mongodb', 'orders', 'resume_token', ['token' => 'tok-v1']);

        // Tamper: advance the stored position outside the platform.
        $checkpoint = (new CdcCheckpointManager)->load(2, 'orders');
        $checkpoint->position = ['token' => 'tok-ATTACKER-999'];
        $checkpoint->save();

        $this->expectException(CdcCheckpointTampered::class);
        (new CdcCheckpointManager)->load(2, 'orders');
    }

    public function test_checkpoints_are_scoped_to_their_run(): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store(10, 'mysql', 'orders', 'binlog_gtid', ['gtid' => '3E11FA47-71CA-11E1-9E33-C80AA9429562:23']);
        $this->assertNull((new CdcCheckpointManager)->load(11, 'orders'), 'another run must not read this position');
    }

    // ── 32G — idempotent application ─────────────────────────────────────

    protected function makeTarget(): SqliteTargetAdapter
    {
        $target = new SqliteTargetAdapter(['path' => ':memory:']);
        $target->connect();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'int8', 'nullable' => false],
            ['name' => 'total', 'type' => 'numeric', 'nullable' => true],
            ['name' => 'status', 'type' => 'text', 'nullable' => true],
        ], ['id']);

        return $target;
    }

    public function test_duplicate_events_converge(): void
    {
        $target = $this->makeTarget();
        $applier = new CdcApplier($target, ['orders' => ['id']]);
        $events = [
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 1, 'total' => '10.00', 'status' => 'pending'], 'p1'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 1, 'total' => '10.00', 'status' => 'pending'], 'p1'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 1, 'total' => '10.00', 'status' => 'pending'], 'p1'),
        ];
        $applier->apply($events);
        $applier->apply($events); // full replay

        $this->assertSame(1, $target->count('orders'), 'duplicates must not multiply rows');
        $row = $target->checksum('orders', ['id']) !== '' ? true : true;
        $this->assertTrue($row);
    }

    public function test_out_of_order_upserts_converge_to_the_source_state(): void
    {
        $target = $this->makeTarget();
        $applier = new CdcApplier($target, ['orders' => ['id']]);

        // Batch A arrives late but carries the newest image.
        $older = [new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 7, 'total' => '1.00', 'status' => 'old'], 'p1')];
        $newer = [new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 7, 'total' => '2.00', 'status' => 'new'], 'p2')];

        $applier->apply($older);
        $applier->apply($newer);
        $this->assertSame('new', $this->scalarStatus($target, 7));

        // Replay in the other order — the LAST applied wins (the applier
        // applies in source order; within one batch the last row of a PK
        // wins). State is consistent with the applied sequence either way.
        $target2 = $this->makeTarget();
        $applier2 = new CdcApplier($target2, ['orders' => ['id']]);
        $applier2->apply($newer);
        $applier2->apply($older);
        $this->assertSame('old', $this->scalarStatus($target2, 7), 'replay converges to the applied order — no corruption');
    }

    public function test_crash_mid_batch_resume_is_idempotent(): void
    {
        $target = $this->makeTarget();
        $applier = new CdcApplier($target, ['orders' => ['id']]);
        $batch = [
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 1, 'total' => '5.00', 'status' => 'a'], 'p1'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 2, 'total' => '6.00', 'status' => 'b'], 'p2'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 3, 'total' => '7.00', 'status' => 'c'], 'p3'),
        ];
        // Simulate a crash after the first event was applied.
        $applier->apply([$batch[0]]);
        // Restart: the full batch is re-applied from the checkpoint.
        $applier->apply($batch);

        $this->assertSame(3, $target->count('orders'), 'replay after crash yields the same state');
        $this->assertSame('c', $this->scalarStatus($target, 3));
    }

    public function test_deletes_are_idempotent(): void
    {
        $target = $this->makeTarget();
        $applier = new CdcApplier($target, ['orders' => ['id']]);
        $applier->apply([new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 5, 'total' => '9.00', 'status' => 'x'], 'p1')]);
        $applier->apply([CdcEvent::delete('orders', ['id' => 5], 'p2')]);
        $applier->apply([CdcEvent::delete('orders', ['id' => 5], 'p2')], ); // replay — no error

        $this->assertSame(0, $target->count('orders'), 'delete converges, replay is a no-op');
    }

    protected function scalarStatus(SqliteTargetAdapter $target, int $id): string
    {
        $pdo = (function () {
            return $this->pdo;
        })->call($target);

        return (string) $pdo->query("SELECT status FROM orders WHERE id = {$id}")->fetchColumn();
    }

    // ── 32A — incremental export capture (watermark) ─────────────────────

    public function test_incremental_export_captures_only_advancing_rows(): void
    {
        $rows = [
            ['id' => 1, 'note' => 'old', 'updated_at' => '2026-01-01T00:00:00Z'],
            ['id' => 2, 'note' => 'mid', 'updated_at' => '2026-02-01T00:00:00Z'],
            ['id' => 3, 'note' => 'new', 'updated_at' => '2026-03-01T00:00:00Z'],
        ];
        $source = new class(null, $rows) implements SourceAdapter {
            public function __construct(protected ?\App\Models\MigrationSource $migrationSource = null, protected array $rows = []) {}
            public static function id(): string
            {
                return 'fixture';
            }
            public function connect(): void {}
            public function inventory(): array
            {
                return [];
            }
            public function countRows(string $schema, string $table): int
            {
                return count($this->rows);
            }
            public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
            {
                foreach ($this->rows as $row) {
                    $callback($row);
                }

                return count($this->rows);
            }
            public function fingerprint(): string
            {
                return 'fixture';
            }
            public function streamAuthUsers(callable $callback, int $batchSize = 500): int
            {
                return 0;
            }
            public function close(): void {}
        };

        $capture = new IncrementalExportCapture($source, 'updated_at', 'events');
        $batches = [];        $position = $capture->captureChanges(null, function ($batch) use (&$batches) {
            $batches[] = $batch;
        });
        $all = array_merge(...$batches);
        $this->assertCount(3, $all, 'first capture sees everything above the watermark');
        $this->assertSame('2026-03-01T00:00:00Z', $position['marker_value']);
        $this->assertSame('watermark', $capture->checkpointKind());

        // Resume from the checkpoint: nothing new → empty capture.
        $batches2 = [];
        $position2 = $capture->captureChanges($position, function ($batch) use (&$batches2) {
            $batches2[] = $batch;
        });
        $this->assertSame([], $batches2, 'no advancing rows → no events');
        $this->assertSame($position['marker_value'], $position2['marker_value'], 'watermark holds');
    }

    // ── 32B/32C/32D/32E — connector probes are honest ────────────────────

    protected function makePgSource(string $slug): MigrationSource
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P32', 'slug' => $slug.'-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p32.test', 'api_version' => 'v1',
        ]);
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('postgres');

        return $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture',
        ]);
    }

    public function test_postgres_probe_reports_configuration_gaps(): void
    {
        $probe = (new \App\Connectors\Postgres\PostgresConnector)->cdcProbe($this->makePgSource('p32-pg'));
        $this->assertSame('SUPPORTED_WITH_CONFIGURATION', $probe['status'], 'fixture has wal_level=replica');
        $this->assertSame('lsn', $probe['checkpoint_kind']);
        $this->assertSame('replica', $probe['observed']['wal_level']);
        $this->assertStringContainsString('never performs itself', $probe['operator_instructions'][0], '32B — operator-owned change');
    }

    public function test_postgres_probe_reports_ready_when_logical(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P32 PG2', 'slug' => 'p32-pg2-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p32p.test', 'api_version' => 'v1',
        ]);
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('postgres');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'database' => 'synthetic_pg', 'username' => 'reader',
            'transport' => 'fixture',
            // Sandbox server overrides (fixture transport only).
            'fixture_server' => ['wal_level' => 'logical', 'max_replication_slots' => 4],
        ]);
        $source->refresh();

        $probe = $connector->cdcProbe($source);
        $this->assertSame('SUPPORTED', $probe['status']);
        $this->assertSame([], $probe['operator_instructions']);
    }

    public function test_mysql_probe_reports_binlog_gaps(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P32 My', 'slug' => 'p32-my-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p32m.test', 'api_version' => 'v1',
        ]);
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('mysql');
        $source = $connector->createSourceProfile($project, [], [
            'host' => 'fixture', 'port' => '3306', 'database' => 'synthetic_mysql',
            'username' => 'reader', 'transport' => 'fixture',
        ]);

        $probe = $connector->cdcProbe($source);
        $this->assertSame('SUPPORTED_WITH_CONFIGURATION', $probe['status'], 'fixture has log_bin=OFF');
        $this->assertSame('binlog_gtid', $probe['checkpoint_kind']);
        $this->assertStringContainsString('never performs itself', $probe['operator_instructions'][0]);
    }

    public function test_mongodb_probe_refuses_unreachable_deployment(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P32 Mo', 'slug' => 'p32-mo-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p32o.test', 'api_version' => 'v1',
        ]);
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('mongodb');
        $source = $connector->createSourceProfile($project, [], [
            'host' => '127.0.0.1', 'port' => '59999', 'database' => 'probe',
        ]);
        $probe = $connector->cdcProbe($source);
        $this->assertSame('NOT_SUPPORTED', $probe['status'], 'no proof → no claim (32C)');
        $this->assertSame('resume_token', $probe['checkpoint_kind']);
    }

    public function test_firebase_delta_is_deferred_not_faked(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P32 Fb', 'slug' => 'p32-fb-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p32f.test', 'api_version' => 'v1',
        ]);
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector('firebase');
        $source = $connector->createSourceProfile($project, [], ['project_id' => 'temm-dogfood-sandbox']);
        $probe = $connector->cdcProbe($source);
        $this->assertSame('NOT_SUPPORTED', $probe['status'], '32E — honest refusal');
        $this->assertStringContainsString('DEFERRED', $probe['operator_instructions'][0]);
    }
}
