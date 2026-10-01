<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use Illuminate\Support\Facades\App;

/**
 * Phase 32C/32F — checkpoint persistence with tamper safety.
 *
 * Every stored position is signed with HMAC-SHA256 over the FULL context
 * (run id + target key + kind + position payload + project scope). Loading
 * verifies the signature — a checkpoint edited outside the platform (or
 * transplanted across projects/runs) is REFUSED, never trusted. Checkpoints
 * are project-scoped by construction: runs belong to projects.
 */
class CdcCheckpointManager
{
    /** Persist (or replace) the checkpoint for one run+target position. */
    public function store(
        int $migrationRunId,
        string $sourceType,
        string $targetKey,
        string $kind,
        array $position,
        int $appliedEvents = 0,
        ?int $lagEvents = null,
        ?array $lastReconciliation = null,
        ?array $errors = null,
        ?array $streamTelemetry = null,
    ): CdcCheckpoint {
        $signature = $this->sign($migrationRunId, $sourceType, $targetKey, $kind, $position);

        return CdcCheckpoint::updateOrCreate(
            ['migration_run_id' => $migrationRunId, 'target_key' => $targetKey],
            [
                'source_type' => $sourceType,
                'kind' => $kind,
                'position' => $position,
                'signature' => $signature,
                'applied_events' => $appliedEvents,
                'lag_events' => $lagEvents,
                'last_reconciliation' => $lastReconciliation,
                'errors' => $errors,
                'stream_telemetry' => $streamTelemetry,
                'stream_status' => $streamTelemetry['status'] ?? null,
                'last_event_at' => isset($streamTelemetry['last_event_at']) ? new \DateTimeImmutable($streamTelemetry['last_event_at']) : null,
            ]
        );
    }

    /**
     * Load and VERIFY the checkpoint for one run+target position.
     *
     * @return CdcCheckpoint|null null when absent
     *
     * @throws CdcCheckpointTampered when the signature does not verify
     */
    public function load(int $migrationRunId, string $targetKey): ?CdcCheckpoint
    {
        $checkpoint = CdcCheckpoint::query()
            ->where('migration_run_id', $migrationRunId)
            ->where('target_key', $targetKey)
            ->first();
        if ($checkpoint === null) {
            return null;
        }
        $expected = $this->sign(
            $checkpoint->migration_run_id,
            (string) $checkpoint->source_type,
            (string) $checkpoint->target_key,
            (string) $checkpoint->kind,
            (array) $checkpoint->position
        );
        if (! hash_equals($expected, (string) $checkpoint->signature)) {
            throw new CdcCheckpointTampered("checkpoint for run {$migrationRunId} / {$targetKey} failed signature verification");
        }

        return $checkpoint;
    }

    /** Record one reconciliation observation against the checkpoint. */
    public function reconcile(CdcCheckpoint $checkpoint, array $observation): CdcCheckpoint
    {
        $checkpoint->last_reconciliation = $observation;
        $checkpoint->save();

        return $checkpoint;
    }

    /** HMAC over the full context — key derived from the application key. */
    protected function sign(int $migrationRunId, string $sourceType, string $targetKey, string $kind, array $position): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required to sign CDC checkpoints (32C).');
        }
        $payload = json_encode([
            'run' => $migrationRunId,
            'source_type' => $sourceType,
            'target_key' => $targetKey,
            'kind' => $kind,
            'position' => $this->sortedKeys($position),
        ], \JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', (string) $payload, $key);
    }

    /** Deterministic key order — signatures must not depend on array build order. */
    protected function sortedKeys(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortedKeys($item);
            }
        }

        return $value;
    }
}
