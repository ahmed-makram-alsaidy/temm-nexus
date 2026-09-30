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
                if ($lastMarker !== null && (string) $marker <= (string) $lastMarker) {
                    return; // only changes ADVANCE the watermark
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
