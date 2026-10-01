<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use Illuminate\Support\Facades\Log;

/**
 * Phase 35.6 — the GENERIC capture/apply/checkpoint loop.
 *
 * Provider-neutral by construction: it consumes normalized CdcEvents
 * through the 32A capture contract, applies them through the 32G applier,
 * and persists signed positions through the 32F checkpoint manager. It
 * NEVER branches on provider and never interprets position payloads.
 *
 * Delivery semantics (documented, 35.6 §14): AT-LEAST-ONCE with an
 * idempotent applier. The checkpoint advances ONLY after captureChanges()
 * has returned — i.e. only after every event it covers has been handed to
 * the applier synchronously. A crash anywhere (mid-apply, mid-cycle, after
 * apply before persist) replays from the last persisted position; replayed
 * upserts/deletes converge. Silent at-most-once loss is impossible by
 * construction: a position is never persisted for events that were not
 * applied.
 *
 * Backpressure (35.6 §13): consumption is bounded per cycle (maxEvents and
 * per-batch rows); the reader cannot outrun the applier into unbounded
 * memory — batches are applied as they are delivered, never buffered
 * whole. A failing target is retried with capped backoff; if it stays
 * down the worker STOPS (checkpoint stays put) — events are never skipped.
 */
class CdcCaptureWorker
{
    protected int $appliedEvents = 0;
    protected int $capturedEvents = 0;
    protected int $cycles = 0;

    public function __construct(
        protected ChangeCaptureConnector $capture,
        protected CdcApplier $applier,
        protected CdcCheckpointManager $checkpoints,
        protected int $migrationRunId,
        protected string $sourceType,
        protected string $targetKey,
        protected array $options = [],
    ) {
    }

    /**
     * Run up to $maxCycles capture cycles. Each cycle: capture from the
     * last persisted position (bounded), apply every event, persist the
     * new signed position, update telemetry.
     *
     * $shouldStop is polled between cycles (stop-file / signal flag).
     * $idleExitSeconds > 0 stops the loop after that many consecutive
     * seconds without new events (caught-up exit for tests/final sync).
     *
     * @return array{cycles: int, captured_events: int, applied_events: int, position: ?array, idle_seconds: int}
     */
    public function run(int $maxCycles, ?callable $shouldStop = null, int $idleExitSeconds = 0): array
    {
        $idleSeconds = 0;
        $maxEvents = max(1, (int) ($this->options['max_events'] ?? config('cdc.capture.max_events_per_cycle', 5000)));
        $idlePollMs = max(0, (int) ($this->options['idle_poll_ms'] ?? config('cdc.capture.idle_poll_ms', 250)));
        $lastPosition = $this->currentPosition()?->position;

        while ($this->cycles < $maxCycles) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            $position = $this->runCycleWithMax($lastPosition, $maxEvents);
            $this->cycles++;

            if ($this->capturedInLastCycle === 0) {
                $idleSeconds += max(1, (int) ceil($idlePollMs / 1000));
                if ($idleExitSeconds > 0 && $idleSeconds >= $idleExitSeconds) {
                    break;
                }
                usleep($idlePollMs * 1000);
            } else {
                $idleSeconds = 0;
            }
            $lastPosition = $position;
        }

        return [
            'cycles' => $this->cycles,
            'captured_events' => $this->capturedEvents,
            'applied_events' => $this->appliedEvents,
            'position' => $lastPosition,
            'idle_seconds' => $idleSeconds,
        ];
    }

    /** Events applied in the most recent cycle (test/telemetry surface). */
    protected int $capturedInLastCycle = 0;

    /**
     * ONE capture cycle from $checkpoint. The checkpoint is persisted only
     * AFTER captureChanges returns (all delivered events applied).
     */
    public function runCycle(?array $checkpoint): array
    {
        return $this->runCycleWithMax($checkpoint, max(1, (int) ($this->options['max_events'] ?? config('cdc.capture.max_events_per_cycle', 5000))));
    }

    public function capturedInLastCycle(): int
    {
        return $this->capturedInLastCycle;
    }

    protected function runCycleWithMax(?array $checkpoint, int $maxEvents): array
    {
        $this->capturedInLastCycle = 0;
        $before = $this->appliedEvents;
        $position = $this->capture->captureChanges(
            $checkpoint,
            function (array $batch): void {
                $this->applyBatch($batch);
                $this->capturedInLastCycle += count($batch);
                $this->capturedEvents += count($batch);
            },
            $maxEvents,
        );
        $appliedThisCycle = $this->appliedEvents - $before;

        $this->persist($position, $appliedThisCycle);

        return $position;
    }

    /** Apply with capped retry/backoff — a slow/down target NEVER skips events. */
    protected function applyBatch(array $batch): void
    {
        $attempts = max(1, (int) ($this->options['apply_retries'] ?? config('cdc.capture.apply_retries', 3)));
        $backoffMs = max(0, (int) ($this->options['apply_backoff_ms'] ?? config('cdc.capture.apply_backoff_ms', 500)));

        $attempt = 0;
        while (true) {
            try {
                $this->appliedEvents += $this->applier->apply($batch);

                return;
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $attempts) {
                    Log::error('cdc.capture.apply_failed', ['run' => $this->migrationRunId, 'error' => $e->getMessage()]);
                    $this->markStreamStatus(CdcStreamTelemetry::ERROR, $e->getMessage());
                    throw $e; // checkpoint NOT advanced — the cycle will be replayed
                }
                usleep($backoffMs * 1000 * $attempt);
            }
        }
    }

    protected function persist(array $position, int $appliedThisCycle): void
    {
        $telemetry = null;
        $lagEvents = null;
        if ($this->capture instanceof CdcPositionSource) {
            $telemetry = CdcStreamTelemetry::fromLagSnapshot($this->capture->lagSnapshot($position));
            $lagEvents = $telemetry->lagEvents;
        }

        $this->checkpoints->store(
            $this->migrationRunId,
            $this->sourceType,
            $this->targetKey,
            $this->capture->checkpointKind(),
            $position,
            $this->appliedEvents,
            $lagEvents,
            null,
            null,
            $telemetry?->toArray(),
        );
    }

    protected function markStreamStatus(string $status, string $error): void
    {
        $checkpoint = $this->checkpoints->load($this->migrationRunId, $this->targetKey);
        if ($checkpoint === null) {
            return;
        }
        $telemetry = is_array($checkpoint->stream_telemetry) ? $checkpoint->stream_telemetry : [];
        $telemetry['status'] = $status;
        $telemetry['error'] = $error;
        $telemetry['updated_at'] = now()->toIso8601String();
        $checkpoint->stream_telemetry = $telemetry;
        $checkpoint->stream_status = $status;
        $checkpoint->save();
    }

    /** Load + verify the current checkpoint payload (tamper refusal, 32F). */
    public function currentPosition(): ?CdcCheckpoint
    {
        return $this->checkpoints->load($this->migrationRunId, $this->targetKey);
    }

    public function appliedEvents(): int
    {
        return $this->appliedEvents;
    }

    public function capturedEvents(): int
    {
        return $this->capturedEvents;
    }
}
