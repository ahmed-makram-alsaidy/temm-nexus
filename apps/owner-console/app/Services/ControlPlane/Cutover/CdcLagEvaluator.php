<?php

namespace App\Services\ControlPlane\Cutover;

use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use App\Services\ControlPlane\Migration\Cdc\CdcStreamTelemetry;

/**
 * Phase 35.6 §16-18 — the REAL cdc_lag gate.
 *
 * Reads normalized stream telemetry from the run's SIGNED checkpoints and
 * returns one gate decision (PASS / WARN / BLOCK / UNVERIFIED) plus the
 * evidence the Cutover Center renders. The generic surface never sees LSN,
 * GTID or resume-token values — only labels, lag and freshness.
 *
 * UNVERIFIED is the honest default: a run without a REAL log-based
 * checkpoint (watermark incremental export only, or nothing at all) does
 * NOT satisfy this gate — incremental export is not CDC and is never
 * passed off as one.
 */
class CdcLagEvaluator
{
    /**
     * @param  int  $runId  migration run id
     * @param  string  $targetKey  checkpoint target key ('' default)
     * @return array{state: string, evidence: string, detail: array<string, mixed>}
     */
    public function evaluate(int $runId, string $targetKey = ''): array
    {
        $checkpoint = CdcCheckpoint::query()
            ->where('migration_run_id', $runId)
            ->where('target_key', $targetKey)
            ->first();
        if ($checkpoint === null) {
            return $this->verdict('UNVERIFIED', 'no CDC checkpoint exists for this run — no live stream has ever reported');
        }

        $logKinds = (array) config('cdc.lag.log_based_kinds', ['lsn', 'binlog_position', 'binlog_gtid', 'resume_token']);
        if (! in_array((string) $checkpoint->kind, $logKinds, true)) {
            return $this->verdict(
                'UNVERIFIED',
                "checkpoint kind '{$checkpoint->kind}' is incremental export, not log-based CDC — it cannot verify lag",
            );
        }

        $telemetry = is_array($checkpoint->stream_telemetry) ? CdcStreamTelemetry::fromArray($checkpoint->stream_telemetry) : null;
        $status = $telemetry?->status ?? (string) ($checkpoint->stream_status ?? '');
        $lagSeconds = $telemetry?->lagSeconds;
        $lagEvents = $telemetry?->lagEvents;
        $detail = $telemetry?->detail ?? [];

        // A disconnected/errored reader BLOCKS: cutover with a dead stream
        // would mean unapplied changes at the switch moment.
        if ($status === CdcStreamTelemetry::ERROR || $status === CdcStreamTelemetry::DISCONNECTED) {
            return $this->verdict('BLOCK', "CDC stream is {$status} — the reader must reconnect and catch up before the window", [
                'status' => $status,
                'error' => $telemetry->detail['error'] ?? null,
            ]);
        }

        // Heartbeat freshness: a stream silent too long is STALE even if it
        // last reported caught-up — the source may have advanced unseen.
        $staleAfter = (int) config('cdc.lag.stale_after_seconds', 120);
        $updatedAt = $checkpoint->updated_at;
        if ($updatedAt !== null && $updatedAt->diffInSeconds(now()) > $staleAfter) {
            return $this->verdict('BLOCK', "CDC telemetry is stale (last update {$updatedAt->toIso8601String()}, older than {$staleAfter}s) — the stream may be down", [
                'status' => $status,
                'last_update' => $updatedAt->toIso8601String(),
            ]);
        }

        $blockSeconds = (float) config('cdc.lag.block_seconds', 300);
        $warnSeconds = (float) config('cdc.lag.warn_seconds', 60);
        $blockEvents = (int) config('cdc.lag.block_events', 10000);
        $warnEvents = (int) config('cdc.lag.warn_events', 1000);

        if ($lagSeconds !== null && $lagSeconds > $blockSeconds) {
            return $this->verdict('BLOCK', "CDC lag ".round($lagSeconds, 1)."s exceeds the BLOCK threshold ({$blockSeconds}s)", $detail);
        }
        if ($lagEvents !== null && $lagEvents > $blockEvents) {
            return $this->verdict('BLOCK', "CDC backlog {$lagEvents} events exceeds the BLOCK threshold ({$blockEvents})", $detail);
        }
        // Reader behind source (provider-reported): source advanced past
        // the applied position.
        $caughtUp = ($detail['byte_lag'] ?? 0) === 0
            && ($detail['cluster_time_lag_seconds'] ?? 0) === 0
            && ($detail['bytes_behind'] ?? 0) === 0;
        if ($status !== CdcStreamTelemetry::CAUGHT_UP && $status !== '' && ! $caughtUp) {
            // ACTIVE with measurable lag under thresholds → WARN.
            return $this->verdict('WARN', "CDC stream is applying (status {$status}) — wait for caught-up before the window", $detail);
        }

        if ($lagSeconds !== null && $lagSeconds > $warnSeconds) {
            return $this->verdict('WARN', "CDC lag ".round($lagSeconds, 1)."s above the WARN threshold ({$warnSeconds}s)", $detail);
        }
        if ($lagEvents !== null && $lagEvents > $warnEvents) {
            return $this->verdict('WARN', "CDC backlog {$lagEvents} events above the WARN threshold ({$warnEvents})", $detail);
        }

        // Non-zero reconciliation is a BLOCK handled by the data gate —
        // here we surface the latest reconciliation state as evidence.
        $reconciliation = is_array($checkpoint->last_reconciliation) ? $checkpoint->last_reconciliation : null;
        $evidence = sprintf(
            'CDC stream %s: %s',
            $status !== '' ? $status : 'active',
            implode('; ', array_filter([
                $telemetry?->sourcePositionLabel ? 'source '.$telemetry->sourcePositionLabel : null,
                $telemetry?->appliedPositionLabel ? 'applied '.$telemetry->appliedPositionLabel : null,
                $lagSeconds !== null ? 'lag '.round($lagSeconds, 1).'s' : null,
                $reconciliation !== null ? 'reconciliation on record' : null,
            ])),
        );

        return $this->verdict('PASS', $evidence, $detail);
    }

    /** @return array{state: string, evidence: string, detail: array<string, mixed>} */
    protected function verdict(string $state, string $evidence, array $detail = []): array
    {
        if (! in_array($state, CutoverCenterService::GATE_STATES, true)) {
            throw new \InvalidArgumentException("invalid gate state '{$state}'");
        }

        return ['state' => $state, 'evidence' => $evidence, 'detail' => $detail];
    }
}
