<?php

namespace App\Connectors\Mysql\Protocol;

/**
 * Phase 31A — query-execution seam for the MySQL/MariaDB source (same
 * pattern as the PostgreSQL connector): the read-only guarantee is enforced
 * in ONE place and the fixture executor (31F sandbox) serves synthetic
 * information_schema results.
 */
interface MysqlExecutor
{
    /**
     * Run a read query and return all rows (associative, lowercase keys).
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
