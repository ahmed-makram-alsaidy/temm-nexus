<?php

namespace App\Services\ControlPlane\Migration\Contracts;

/**
 * Target adapter contract (24J.1). PostgreSQL/Laravel is the platform target;
 * a SQLite target exists for engine tests and disposable local dry-runs.
 *
 * Destructive operations (truncate/reset) must only be called through the
 * MigrationRunManager, which enforces the disposable-target guard.
 */
interface TargetAdapter
{
    public function __construct(array $config);

    public function connect(): void;

    public function adapterId(): string;

    /** Create the table with the given normalized columns (schema build). */
    public function ensureTable(string $table, array $columns, array $primaryKey): void;

    /** Apply FK constraints after all tables exist (dependency order). */
    public function applyForeignKeys(array $foreignKeys): void;

    /** Create an enum-like type where the adapter supports it. */
    public function ensureEnum(string $name, array $values): void;

    /** Insert rows, idempotent on PK (upsert) so resume/clean-rerun is safe. */
    public function insertBatch(string $table, array $rows): int;

    /**
     * Phase 32G — apply CDC/incremental events idempotently: each row is
     * upserted by primary key, so duplicate or out-of-order application can
     * never corrupt target state. Implementations must converge to the same
     * state regardless of event replay order.
     */
    public function upsertBatch(string $table, array $rows, array $primaryKey): int;

    /**
     * Phase 32G — delete one row by primary key. Idempotent: deleting an
     * absent row is a no-op (returns false), never an error.
     */
    public function deleteByPk(string $table, array $pkRow): bool;

    /** Reset ONLY a disposable target (guarded upstream). */
    public function truncateTable(string $table): void;

    public function count(string $table): int;

    public function tableExists(string $table): bool;

    public function setSequence(string $table, string $column, int $value): void;

    public function sequenceValue(string $table, string $column): ?int;

    public function orphanCount(string $table, string $column, string $refTable, string $refColumn): int;

    /** Deterministic content checksum ordered by PK columns. */
    public function checksum(string $table, array $pkColumns, ?int $sampleEvery = null): string;

    public function distinctValues(string $table, string $column): array;

    public function close(): void;
}
