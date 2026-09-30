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
        $since = $checkpoint['marker_value'] ?? null;
        $batch = [];
        $lastMarker = $since;
        $consumed = 0;

        $this->adapter->streamRows(
            $this->schemaName(),
            $this->table,
            [],
            function (array $row) use (&$batch, &$lastMarker, &$consumed, $onBatch, $maxEvents): void {
                $marker = $row[$this->markerColumn] ?? null;
                if ($marker === null) {
                    return; // rows without a marker cannot be ordered — skipped honestly
                }
                // 35.5 live finding: the filter used to skip rows whose
                // marker EQUALLED the watermark (strict advance). Markers
                // with second precision collide constantly (bulk statements
                // share one timestamp), so every colliding change was
                // silently LOST. Rows at the boundary watermark are now
                // re-exported: applying them again is an idempotent upsert,
                // and rows mutated after the previous cycle still carry
                // marker == watermark and are therefore picked up. The
                // position advances only when a strictly greater marker
                // appears, so idle cycles re-export just the boundary set.
                if ($lastMarker !== null && (string) $marker < (string) $lastMarker) {
                    return; // rows below the watermark are already applied
                }
                $batch[] = CdcEvent::upsert($this->table, $row, (string) $marker);
                $lastMarker = $marker;
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
            'marker_value' => $lastMarker,
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
