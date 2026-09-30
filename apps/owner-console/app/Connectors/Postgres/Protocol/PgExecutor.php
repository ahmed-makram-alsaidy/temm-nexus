<?php

namespace App\Connectors\Postgres\Protocol;

/**
 * Phase 30A/30B — query-execution seam for the PostgreSQL source.
 *
 * The connector speaks ONLY read SQL (SELECT/SHOW) — the executor boundary
 * exists so the read-only guarantee is enforced in ONE place (PdoPgExecutor
 * pins the session read-only) and so the fixture executor (30D sandbox) can
 * serve synthetic pg_catalog results without any server at all.
 */
interface PgExecutor
{
    /**
     * Run a read query and return all rows (associative).
     *
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $bindings = []): array;

    /** Run a single-valued read query. */
    public function scalar(string $sql, array $bindings = []): mixed;

    /** Which implementation is backing the connector (fixture|pdo). */
    public function name(): string;
}
