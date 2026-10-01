<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;

/**
 * Phase 32A — generic INCREMENTAL EXPORT capture (watermark-based).
 *
 * The honest fallback when a provider has no reliable change stream
 * (32E): rows changed since the watermark are re-exported and applied as
 * UPSERT events. Requirements: a monotonically-advancing marker column
 * (updated_at / version / append-only PK) on the source table. This is
 * NOT log-based CDC — deleted rows are not captured — and it never claims
 * to be: sources with real streams implement ChangeCaptureConnector with
 * true positions; this capture records 'watermark' checkpoints.
 *
 * Marker collisions (35.5 live finding): rows sharing the boundary marker
 * are RE-EXPORTED on every cycle and applied as idempotent upserts, so
 * same-timestamp mutations can never be silently lost. The position
 * advances only past strictly smaller markers.
 *
 * Memory is bounded (batched keyset reads, 29E/30D/31D adapters) and the
 * watermark checkpoint is resumable (32F/32G).
 */
class IncrementalExportCapture implements ChangeCaptureConnector
{
    public function __construct(
        protected SourceAdapter $adapter,
        protected string $markerColumn = 'updated_at',
        protected string $table = '',
    ) {
    }

    public function checkpointKind(): string
    {
        return 'watermark';
    }

    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array
    {
        // The checkpoint watermark: only rows AT or ABOVE it are re-exported
        // (inclusive — see the collision note below).
        $since = $checkpoint['marker_value'] ?? null;
        $batch = [];
        $consumed = 0;
        $maxSeen = $since;

        $this->adapter->streamRows(
            $this->schemaName(),
            $this->table,
            [],
            function (array $row) use (&$batch, &$consumed, &$maxSeen, $since, $onBatch, $maxEvents): void {
                $marker = $row[$this->markerColumn] ?? null;
                if ($marker === null) {
                    return; // rows without a marker cannot be ordered — skipped honestly
                }
                // 35.5 live findings (two, both fixed here):
                //  1. The old filter skipped rows whose marker EQUALLED the
                //     running watermark. Markers with second precision collide
                //     constantly (bulk statements share one timestamp), so
                //     colliding changes were silently LOST.
                //  2. The old filter compared against the last row's marker in
                //     STREAM order — adapters stream by primary key, so any
                //     row whose marker was below a previously streamed row's
                //     marker was also lost.
                // The filter is now order-independent: a row is re-exported
                // when its marker is at or above the STORED watermark from
                // the previous cycle. Applying those rows again is an
                // idempotent upsert, so the inclusive re-export is safe; the
                // new watermark is the maximum marker seen in this pass.
                if ($since !== null && (string) $marker < (string) $since) {
                    return; // strictly below the previous watermark — already applied
                }
                $batch[] = CdcEvent::upsert($this->table, $row, (string) $marker);
                if ($maxSeen === null || (string) $marker > (string) $maxSeen) {
                    $maxSeen = (string) $marker;
                }
                $consumed++;
                if (count($batch) >= 100) {
                    $onBatch($batch);
                    $batch = [];
                }
            },
            500
        );
        if ($batch !== []) {
            $onBatch($batch);
        }

        return [
            'marker_column' => $this->markerColumn,
            'marker_value' => $maxSeen,
            'table' => $this->table,
            'events' => $consumed,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    protected function schemaName(): string
    {
        return '';
    }
}
