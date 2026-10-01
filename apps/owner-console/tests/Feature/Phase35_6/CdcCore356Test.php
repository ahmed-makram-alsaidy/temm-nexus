<?php

namespace Tests\Feature\Phase35_6;


use App\Services\ControlPlane\Cutover\CdcLagEvaluator;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use App\Services\ControlPlane\Migration\Cdc\CdcApplier;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpointManager;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpointTampered;
use App\Services\ControlPlane\Migration\Cdc\CdcEvent;
use App\Services\ControlPlane\Migration\Cdc\CdcStreamTelemetry;
use App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector;
use App\Services\ControlPlane\Migration\Contracts\TargetAdapter;
use App\Services\ControlPlane\Migration\TargetAdapters\SqliteTargetAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 35.6 — the GENERIC capture loop end to end against a real SQLite
 * target: checkpoint safety (§14: a position never advances past unapplied
 * changes), at-least-once replay with idempotent convergence, bounded
 * retries (§13), normalized DELETE support (§11) and the REAL cdc_lag
 * gate states (§17-18).
 */
class CdcCore356Test extends TestCase
{
    use RefreshDatabase;

    // ── §14 — checkpoint safety ──────────────────────────────────────────

    public function test_checkpoint_advances_only_after_events_are_applied(): void
    {
        $target = $this->sqliteTarget();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
            ['name' => 'v', 'type' => 'text', 'nullable' => true],
        ], ['id']);

        $capture = new FakeCapture([
            // cycle 1: two events delivered, position advances
            [new CdcEvent('orders', CdcEvent::INSERT, ['id' => 1, 'v' => 'a'], '0/10'),
                new CdcEvent('orders', CdcEvent::INSERT, ['id' => 2, 'v' => 'b'], '0/20')],
            // cycle 2: nothing new
            [],
        ], [['lsn' => '0/20'], ['lsn' => '0/20']]);

        $worker = new WorkerHarness($capture, new CdcApplier($target, ['orders' => ['id']]), 7, 'lsn');
        $worker->worker->runCycle(null);

        $checkpoint = (new CdcCheckpointManager)->load(7, '');
        $this->assertSame('0/20', $checkpoint->position['lsn']);
        $this->assertSame(2, $checkpoint->applied_events);
        $this->assertSame(2, $target->count('orders'));
    }

    public function test_crash_between_apply_and_persist_replays_idempotently(): void
    {
        $target = $this->sqliteTarget();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
            ['name' => 'v', 'type' => 'text', 'nullable' => true],
        ], ['id']);

        // Cycle 1: events applied, then the capture "crashes" BEFORE the
        // position is returned (the worker persists nothing).
        $capture = new FakeCapture(
            [[new CdcEvent('orders', CdcEvent::INSERT, ['id' => 1, 'v' => 'a'], '0/10')]],
            [['lsn' => '0/10']],
            crashAfterDeliver: true,
        );
        $worker = new WorkerHarness($capture, new CdcApplier($target, ['orders' => ['id']]), 9, 'lsn');
        try {
            $worker->worker->runCycle(null);
            $this->fail('expected the simulated crash to throw');
        } catch (\RuntimeException) {
        }
        $this->assertNull((new CdcCheckpointManager)->load(9, ''), 'no checkpoint after the crash');
        $this->assertSame(1, $target->count('orders'), 'the event WAS applied');

        // Cycle 2 (the restart): replay from the BEGINNING — the applier
        // converges, nothing is duplicated or lost (§14 at-least-once).
        $replay = new FakeCapture(
            [[new CdcEvent('orders', CdcEvent::INSERT, ['id' => 1, 'v' => 'a'], '0/10'),
                new CdcEvent('orders', CdcEvent::INSERT, ['id' => 2, 'v' => 'b'], '0/20')]],
            [['lsn' => '0/20']],
        );
        $worker2 = new WorkerHarness($replay, new CdcApplier($target, ['orders' => ['id']]), 9, 'lsn');
        $worker2->worker->runCycle(null);
        $checkpoint = (new CdcCheckpointManager)->load(9, '');
        $this->assertSame('0/20', $checkpoint->position['lsn']);
        $this->assertSame(2, $target->count('orders'));
        $this->assertSame(2, $checkpoint->applied_events, 'replayed cycle applied both events (fresh worker counter; target state is the truth)');
    }

    // ── §11 — normalized DELETE support ──────────────────────────────────

    public function test_delete_events_remove_rows_idempotently(): void
    {
        $target = $this->sqliteTarget();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
            ['name' => 'v', 'type' => 'text', 'nullable' => true],
        ], ['id']);
        $target->insertBatch('orders', [['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b']]);

        $capture = new FakeCapture(
            [[new CdcEvent('orders', CdcEvent::DELETE, ['id' => 1], '0/30')]],
            [['lsn' => '0/30']],
        );
        $applier = new CdcApplier($target, ['orders' => ['id']]);
        $worker = new WorkerHarness($capture, $applier, 11, 'lsn');

        $worker->worker->runCycle(null);
        $this->assertSame(1, $target->count('orders'));

        // Replay the SAME delete — idempotent, no error (§11).
        $worker->worker->runCycle(['lsn' => '0/30']);
        $this->assertSame(1, $target->count('orders'));
    }

    public function test_insert_update_delete_mix_converges_in_one_batch(): void
    {
        $target = $this->sqliteTarget();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
            ['name' => 'v', 'type' => 'text', 'nullable' => true],
        ], ['id']);
        $target->insertBatch('orders', [['id' => 3, 'v' => 'old']]);

        $batch = [
            new CdcEvent('orders', CdcEvent::INSERT, ['id' => 1, 'v' => 'one'], '0/1'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 1, 'v' => 'one!'], '0/2'),
            new CdcEvent('orders', CdcEvent::UPDATE, ['id' => 3, 'v' => 'updated'], '0/3'),
            new CdcEvent('orders', CdcEvent::DELETE, ['id' => 2], '0/4'), // absent row — no-op
        ];
        $applied = (new CdcApplier($target, ['orders' => ['id']]))->apply($batch);
        $this->assertSame(3, $applied, 'the delete of an absent row is a no-op (idempotent)');
        $this->assertSame(2, $target->count('orders'));
        $rows = $target->distinctValues('orders', 'v');
        $this->assertEqualsCanonicalizing(['one!', 'updated'], $rows);
    }

    // ── §13 — backpressure: a failing target never skips events ─────────

    public function test_failing_target_retries_then_stops_without_checkpoint(): void
    {
        $capture = new FakeCapture(
            [[new CdcEvent('orders', CdcEvent::INSERT, ['id' => 1, 'v' => 'a'], '0/10')]],
            [['lsn' => '0/10']],
        );
        $applier = new CdcApplier(new class implements TargetAdapter
        {
            public function __construct(public array $config = [])
            {
            }

            public function connect(): void
            {
            }

            public function adapterId(): string
            {
                return 'failing';
            }

            public function ensureTable(string $table, array $columns, array $primaryKey): void
            {
            }

            public function applyForeignKeys(array $foreignKeys): void
            {
            }

            public function ensureEnum(string $name, array $values): void
            {
            }

            public function insertBatch(string $table, array $rows): int
            {
                throw new \RuntimeException('target down');
            }

            public function upsertBatch(string $table, array $rows, array $primaryKey): int
            {
                throw new \RuntimeException('target down');
            }

            public function deleteByPk(string $table, array $pkRow): bool
            {
                throw new \RuntimeException('target down');
            }

            public function truncateTable(string $table): void
            {
            }

            public function count(string $table): int
            {
                return 0;
            }

            public function tableExists(string $table): bool
            {
                return true;
            }

            public function setSequence(string $table, string $column, int $value): void
            {
            }

            public function sequenceValue(string $table, string $column): ?int
            {
                return null;
            }

            public function orphanCount(string $table, string $column, string $refTable, string $refColumn): int
            {
                return 0;
            }

            public function checksum(string $table, array $pkColumns, ?int $sampleEvery = null): string
            {
                return '';
            }

            public function distinctValues(string $table, string $column): array
            {
                return [];
            }

            public function close(): void
            {
            }
        }, ['orders' => ['id']]);

        $worker = new WorkerHarness($capture, $applier, 13, 'lsn', ['apply_retries' => 2, 'apply_backoff_ms' => 1]);
        try {
            $worker->worker->runCycle(null);
            $this->fail('expected exhaustion to throw after retries');
        } catch (\RuntimeException) {
        }
        $this->assertNull((new CdcCheckpointManager)->load(13, ''), 'checkpoint NOT advanced past unapplied events');
    }

    // ── §17/§18 — the REAL cdc_lag gate ──────────────────────────────────

    public function test_lag_gate_is_unverified_without_a_real_cdc_checkpoint(): void
    {
        // No checkpoint at all.
        $this->assertSame('UNVERIFIED', (new CdcLagEvaluator)->evaluate(501)['state']);

        // A WATERMARK checkpoint is incremental export — never log CDC.
        (new CdcCheckpointManager)->store(502, 'postgres', '', 'watermark', ['marker_value' => '2026-01-01'], 5);
        $verdict = (new CdcLagEvaluator)->evaluate(502);
        $this->assertSame('UNVERIFIED', $verdict['state']);
        $this->assertStringContainsString('not log-based CDC', $verdict['evidence']);
    }

    public function test_lag_gate_passes_when_caught_up_and_fresh(): void
    {
        $this->storeTelemetry(511, 'lsn', CdcStreamTelemetry::CAUGHT_UP, lagSeconds: 0.0, updatedSecondsAgo: 5);
        $verdict = (new CdcLagEvaluator)->evaluate(511);
        $this->assertSame('PASS', $verdict['state']);
        $this->assertStringContainsString('WAL LSN', $verdict['evidence']);
    }

    public function test_lag_gate_warns_between_thresholds(): void
    {
        $this->storeTelemetry(512, 'lsn', CdcStreamTelemetry::ACTIVE, lagSeconds: 120.0, updatedSecondsAgo: 2);
        $verdict = (new CdcLagEvaluator)->evaluate(512);
        $this->assertSame('WARN', $verdict['state'], '120s is above warn(60) below block(300)');
    }

    public function test_lag_gate_blocks_when_lag_exceeds_block_threshold(): void
    {
        $this->storeTelemetry(513, 'lsn', CdcStreamTelemetry::ACTIVE, lagSeconds: 900.0, updatedSecondsAgo: 2);
        $this->assertSame('BLOCK', (new CdcLagEvaluator)->evaluate(513)['state']);
    }

    public function test_lag_gate_blocks_on_erroring_or_disconnected_stream(): void
    {
        $this->storeTelemetry(514, 'lsn', CdcStreamTelemetry::DISCONNECTED, lagSeconds: 0.0, updatedSecondsAgo: 2);
        $verdict = (new CdcLagEvaluator)->evaluate(514);
        $this->assertSame('BLOCK', $verdict['state']);
        $this->assertStringContainsString('disconnected', $verdict['evidence']);
    }

    public function test_lag_gate_blocks_on_stale_telemetry(): void
    {
        $this->storeTelemetry(515, 'lsn', CdcStreamTelemetry::CAUGHT_UP, lagSeconds: 0.0, updatedSecondsAgo: 600);
        $verdict = (new CdcLagEvaluator)->evaluate(515);
        $this->assertSame('BLOCK', $verdict['state']);
        $this->assertStringContainsString('stale', $verdict['evidence']);
    }

    public function test_lag_gate_blocks_on_event_backlog(): void
    {
        config(['cdc.lag.block_events' => 100]);
        $this->storeTelemetry(516, 'lsn', CdcStreamTelemetry::ACTIVE, lagSeconds: 1.0, updatedSecondsAgo: 1, lagEvents: 500);
        $this->assertSame('BLOCK', (new CdcLagEvaluator)->evaluate(516)['state']);
    }

    // ── §2/§25 — positions are signed and scope-bound ────────────────────

    public function test_checkpoint_scope_transplants_are_refused(): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store(601, 'postgres', '', 'lsn', ['lsn' => '0/100']);

        // Wrong-project/run transplant: the position payload is copied to
        // another run — the signature covers (run, source, target, kind,
        // position) so the transplant is REFUSED, never trusted.
        CdcCheckpoint::query()->where('migration_run_id', 601)->update(['migration_run_id' => 602]);
        $this->expectException(CdcCheckpointTampered::class);
        $manager->load(602, '');
    }

    public function test_checkpoint_connector_swap_is_refused(): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store(611, 'postgres', '', 'lsn', ['lsn' => '0/100']);

        // A position captured from a POSTGRES stream rebranded as a mongo
        // resume token — signature mismatch, refused.
        CdcCheckpoint::query()->where('migration_run_id', 611)->update(['source_type' => 'mongodb', 'kind' => 'resume_token']);
        $this->expectException(CdcCheckpointTampered::class);
        $manager->load(611, '');
    }

    // ── §16 — telemetry is persisted beside the signed position ──────────

    public function test_worker_persists_normalized_telemetry(): void
    {
        $target = $this->sqliteTarget();
        $target->ensureTable('orders', [
            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
        ], ['id']);

        $capture = new FakeCapture([], [['lsn' => '0/40', 'source_wal_end' => '0/40']], telemetry: [
            'status' => 'caught_up',
            'source_position_label' => 'WAL LSN 0/40',
            'applied_position_label' => 'WAL LSN 0/40',
            'lag_seconds' => 0.0,
            'lag_events' => null,
            'last_event_at' => '2026-10-01T00:00:00Z',
            'mechanism' => 'logical_replication (pgoutput)',
            'detail' => ['byte_lag' => 0],
        ]);
        $worker = new WorkerHarness($capture, new CdcApplier($target, ['orders' => ['id']]), 621, 'lsn');
        $worker->worker->runCycle(null);

        $checkpoint = (new CdcCheckpointManager)->load(621, '');
        $this->assertSame('caught_up', $checkpoint->stream_status);
        $this->assertSame('WAL LSN 0/40', $checkpoint->stream_telemetry['source_position_label']);
        $this->assertNotNull($checkpoint->last_event_at);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    protected function sqliteTarget(): SqliteTargetAdapter
    {
        $target = new SqliteTargetAdapter(['path' => tempnam(sys_get_temp_dir(), 'cdc356_'), 'recreate' => true]);
        $target->connect();

        return $target;
    }

    protected function storeTelemetry(int $runId, string $kind, string $status, float $lagSeconds, int $updatedSecondsAgo, ?int $lagEvents = null): void
    {
        $manager = new CdcCheckpointManager;
        $manager->store($runId, 'postgres', '', $kind, ['lsn' => '0/40'], 10, $lagEvents, null, null, [
            'status' => $status,
            'source_position_label' => 'WAL LSN 0/40',
            'captured_position_label' => 'WAL LSN 0/40',
            'applied_position_label' => 'WAL LSN 0/40',
            'lag_seconds' => $lagSeconds,
            'lag_events' => $lagEvents,
            'last_event_at' => '2026-10-01T00:00:00Z',
            'mechanism' => 'logical_replication (pgoutput)',
            'detail' => ['byte_lag' => 0],
            'updated_at' => now()->toIso8601String(),
        ]);
        // Age the checkpoint row to test the staleness BLOCK.
        if ($updatedSecondsAgo > 0) {
            CdcCheckpoint::query()->where('migration_run_id', $runId)
                ->update(['updated_at' => now()->subSeconds($updatedSecondsAgo)]);
        }
    }
}

/**
 * Scripted capture for worker tests: delivers batch N (optionally crashing
 * after delivery to simulate a kill between apply and persist) and returns
 * position N.
 */
class FakeCapture implements ChangeCaptureConnector
{
    protected int $call = 0;

    public function __construct(
        protected array $batches,
        protected array $positions,
        protected bool $crashAfterDeliver = false,
        protected ?array $telemetry = null,
    ) {
    }

    public function checkpointKind(): string
    {
        return 'lsn';
    }

    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
    {
        $call = $this->call++;
        $batch = $this->batches[$call] ?? [];
        if ($batch !== []) {
            $onBatch($batch);
            if ($this->crashAfterDeliver) {
                throw new \RuntimeException('simulated crash after delivery (checkpoint not persisted)');
            }
        }
        $position = $this->positions[$call] ?? ['lsn' => '0/0'];
        if ($this->telemetry !== null) {
            $position['telemetry'] = $this->telemetry;
        }

        return $position;
    }
}

/**
 * Worker with a telemetry-aware fake: FakeCapture positions may carry a
 * 'telemetry' key the worker reads via the CdcPositionSource seam — for
 * tests we expose it through a capture implementing CdcPositionSource.
 */
class WorkerHarness
{
    public \App\Services\ControlPlane\Migration\Cdc\CdcCaptureWorker $worker;

    public function __construct(
        FakeCapture $capture,
        CdcApplier $applier,
        int $runId,
        string $kind,
        array $options = [],
    ) {
        $telemetryCapture = new class($capture) implements
            \App\Services\ControlPlane\Migration\Cdc\ChangeCaptureConnector,
            \App\Services\ControlPlane\Migration\Cdc\CdcPositionSource
        {
            public function __construct(protected FakeCapture $inner)
            {
            }

            public function checkpointKind(): string
            {
                return $this->inner->checkpointKind();
            }

            public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
            {
                return $this->inner->captureChanges($checkpoint, $onBatch, $maxEvents);
            }

            public function currentSourcePosition(): array
            {
                return ['position' => ['lsn' => '0/0'], 'label' => 'WAL LSN 0/0', 'captured_at' => now()->toIso8601String()];
            }

            public function hasAppliedThrough(?array $checkpoint, array $sourcePosition): bool
            {
                return false;
            }

            public function lagSnapshot(?array $checkpoint): array
            {
                return $checkpoint['telemetry'] ?? [
                    'status' => 'idle', 'source_position_label' => null, 'captured_position_label' => null,
                    'applied_position_label' => null, 'lag_seconds' => null, 'lag_events' => null,
                    'last_event_at' => null, 'mechanism' => 'test', 'detail' => [],
                ];
            }
        };

        $this->worker = new \App\Services\ControlPlane\Migration\Cdc\CdcCaptureWorker(
            $telemetryCapture,
            $applier,
            new CdcCheckpointManager,
            $runId,
            'postgres',
            '',
            $options,
        );
    }
}
