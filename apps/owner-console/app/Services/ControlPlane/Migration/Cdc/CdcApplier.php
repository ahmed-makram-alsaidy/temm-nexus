<?php

namespace App\Services\ControlPlane\Migration\Cdc;

use App\Services\ControlPlane\Migration\Contracts\TargetAdapter;

/**
 * Phase 32G — idempotent event application.
 *
 * Every event is applied as an upsert (or PK delete) against the target, so
 * duplicates, out-of-order events, disconnects, restarts and mid-batch
 * crashes converge to the same target state. The applier NEVER invents
 * ordering it cannot know — ordering correctness is the capture side's
 * responsibility (source-order batches, 32A contract).
 */
class CdcApplier
{
    /** Per-table primary keys, resolved from the plan/analysis. */
    protected array $tableKeys;

    public function __construct(protected TargetAdapter $target, array $tableKeys = [])
    {
        $this->tableKeys = $tableKeys;
    }

    /**
     * Apply a batch of events. Returns the number of applied events.
     * Deletes are applied AFTER upserts of the same batch — the batch is a
     * source-order slice, so this matches the provider's own semantics.
     */
    public function apply(iterable $events): int
    {
        $upserts = [];
        $deletes = [];
        foreach ($events as $event) {
            if (! $event instanceof CdcEvent) {
                throw new \InvalidArgumentException('CdcApplier applies CdcEvent instances only');
            }
            if ($event->isDelete()) {
                $deletes[$event->table][] = $event->row;
            } else {
                $upserts[$event->table][] = $event->row;
            }
        }

        $applied = 0;
        foreach ($upserts as $table => $rows) {
            $applied += $this->target->upsertBatch($table, $rows, $this->primaryKeyFor($table));
        }
        foreach ($deletes as $table => $pkRows) {
            foreach ($pkRows as $pkRow) {
                $applied += $this->deleteByPk($table, $pkRow);
            }
        }

        return $applied;
    }

    protected function deleteByPk(string $table, array $pkRow): int
    {
        // Narrow the delete to the PLAN's primary key columns when they are
        // known. Log-based captures deliver whole old-row images (MySQL
        // binlog_row_image=FULL); matching on decoded non-key columns
        // (JSON, blobs, floats) is fragile and can silently miss the row —
        // the PK is the identity the applier contract speaks in (35.6 live
        // finding).
        $keys = $this->tableKeys[$table] ?? [];
        if ($keys !== []) {
            $narrowed = array_intersect_key($pkRow, array_flip($keys));
            if ($narrowed !== []) {
                $pkRow = $narrowed;
            }
        }

        return $this->target->deleteByPk($table, $pkRow) ? 1 : 0;
    }

    protected function primaryKeyFor(string $table): array
    {
        return $this->tableKeys[$table] ?? ['id'];
    }
}
