<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 32A — the generic change-capture contract a connector MAY
 * implement. Nothing provider-specific enters the core: the core drives
 * capture through this contract, checkpoints flow through the
 * CdcCheckpointManager (32F) and changes apply through the CdcApplier (32G).
 *
 * Implementations must be honest (32A/32E): a source whose change semantics
 * cannot be made robust reports NOT_SUPPORTED via capabilityStatus and does
 * not implement this contract.
 */
interface ChangeCaptureConnector
{
    /**
     * The checkpoint kind this source uses, e.g. 'lsn', 'resume_token',
     * 'binlog_gtid', 'watermark'. Recorded verbatim in checkpoints (32F).
     */
    public function checkpointKind(): string;

    /**
     * Capture changes since $checkpoint (null = from the snapshot boundary)
     * and hand them to $onBatch in source order. Returns the NEW checkpoint
     * to persist. Implementations MUST bound memory (batched consumption,
     * never a full backlog load) and MUST be resumable from the returned
     * checkpoint after a crash (32G).
     *
     * @param  array|null  $checkpoint  the last persisted position payload
     * @param  callable(list<CdcEvent>): void  $onBatch
     * @param  int  $maxEvents  upper bound of events consumed per call
     * @return array the new position payload
     */
    public function captureChanges(?array $checkpoint, callable $onBatch, int $maxEvents = 1000): array;
}
