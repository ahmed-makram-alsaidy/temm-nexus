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
