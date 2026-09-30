<?php

namespace App\Connectors\Postgres\Protocol;

/**
 * Phase 30D — in-memory executor serving the synthetic database fixture.
 *
 * Interprets the dataset into pg_catalog-shaped result rows so every catalog
 * query, type normalization and extraction path in the connector is
 * exercised against realistic metadata with zero network I/O and zero risk
 * to any real database. Read-only by construction: only SELECT/SHOW are
 * routable, everything else throws.
 */
class FixturePgExecutor implements PgExecutor
{
    protected array $data;

    public function __construct(?array $dataset = null)
    {
        $this->data = $dataset ?? require __DIR__.'/../datasets/synthetic-database.php';
    }

    public function name(): string
    {
        return 'fixture';
    }

    public function rows(string $sql, array $bindings = []): array
    {
        return $this->route($sql, $bindings);
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $rows = $this->route($sql, $bindings);
        if ($rows === []) {
            return null;
        }

        return array_values($rows[0])[0] ?? null;
    }

    protected function route(string $sql, array $bindings): array
    {
        self::assertReadSql($sql);

        // ── server info ──────────────────────────────────────────────────
        if (str_contains($sql, "current_setting('server_version_num')")) {
            return [['server_version_num' => (string) $this->data['server']['version_num']]];
        }
        if (str_contains($sql, 'FROM pg_settings')) {
            $rows = [
                ['name' => 'default_transaction_read_only', 'setting' => 'on'],
                ['name' => 'transaction_read_only', 'setting' => 'on'],
                ['name' => 'server_encoding', 'setting' => $this->data['server']['server_encoding']],
                ['name' => 'lc_ctype', 'setting' => $this->data['server']['lc_ctype']],
                ['name' => 'max_identifier_length', 'setting' => '63'],
            ];

            return isset($bindings[0]) ? array_filter($rows, fn ($r) => str_contains($sql, $r['name'])) : $rows;
        }

        // ── schemas ──────────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_namespace n') && str_contains($sql, 'NOT LIKE')) {
            return array_map(fn ($s) => ['nspname' => $s], $this->data['schemas']);
        }

        // ── tables (relkind r/p) ─────────────────────────────────────────
        if (str_contains($sql, "relkind IN ('r', 'p')")) {
            $schema = $bindings[0];
            $out = [];
            foreach ($this->data['tables'][$schema] ?? [] as $name => $def) {
                $out[] = [
                    'oid' => 'oid-'.md5($schema.$name),
                    'relname' => $name,
                    'relkind' => $def['relkind'] ?? 'r',
                    'relrowsecurity' => ($def['policies'] ?? []) !== [] ? 't' : 'f',
                    'relforcerowsecurity' => 'f',
                    'row_estimate' => count($def['rows'] ?? []),
                    'partition_bound' => null,
                    'has_partitions' => ($def['partitions'] ?? []) !== [] ? 't' : 'f',
                ];
            }

            return $out;
        }

        // ── columns ──────────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_attribute a')) {
            [$schema, $table] = $bindings;
            $out = [];
            foreach ($this->data['tables'][$schema][$table]['columns'] ?? [] as $i => $col) {
                $out[] = [
                    'attname' => $col['name'],
                    'attnum' => $i + 1,
                    'attnotnull' => ! empty($col['notnull']) ? 't' : 'f',
                    'attidentity' => $col['identity'] ?? '',
                    'attgenerated' => ! empty($col['generated']) ? 's' : '',
                    'pg_type' => $col['pg_type'],
                    'typcategory' => 'U',
                    'typtype' => in_array($col['pg_type'], $this->data['enums'][$schema] ?? [], true) || isset(($this->data['enums'][$schema] ?? [])[$col['pg_type']]) ? 'e' : 'b',
                    'default_expr' => $col['default'] ?? null,
                    'is_identity' => ! empty($col['identity']) ? 't' : 'f',
                    'is_generated' => ! empty($col['generated']) ? 't' : 'f',
                ];
            }

            return $out;
        }

        // ── primary key ──────────────────────────────────────────────────
        if (str_contains($sql, "con.contype = 'p'")) {
            [$schema, $table] = $bindings;

            return array_map(
                fn ($col) => ['attname' => $col],
                $this->data['tables'][$schema][$table]['primary_key'] ?? []
            );
        }

        // ── foreign keys ─────────────────────────────────────────────────
        if (str_contains($sql, "con.contype = 'f'")) {
            [$schema, $table] = $bindings;

            return $this->data['tables'][$schema][$table]['foreign_keys'] ?? [];
        }

        // ── check / unique constraints ───────────────────────────────────
        if (str_contains($sql, "contype IN ('c', 'u', 'x')")) {
            [$schema, $table] = $bindings;

            return $this->data['tables'][$schema][$table]['checks'] ?? [];
        }

        // ── indexes ──────────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_index idx')) {
            [$schema, $table] = $bindings;

            return $this->data['tables'][$schema][$table]['indexes'] ?? [];
        }

        // ── views / matviews ─────────────────────────────────────────────
        if (str_contains($sql, "relkind IN ('v', 'm')")) {
            $schema = $bindings[0];
            $out = [];
            foreach ($this->data['views'][$schema] ?? [] as $view) {
                $out[] = [
                    'relname' => $view['relname'],
                    'relkind' => ! empty($view['matview']) ? 'm' : 'v',
                    'definition' => $view['definition'],
                ];
            }

            return $out;
        }

        // ── sequences ────────────────────────────────────────────────────
        if (str_contains($sql, "relkind = 'S'")) {
            $schema = $bindings[0];

            return array_values(array_filter(
                $this->data['sequences'] ?? [],
                fn ($s) => $s['sequence_schema'] === $schema
            ));
        }

        // ── functions / procedures ───────────────────────────────────────
        if (str_contains($sql, 'FROM pg_proc p')) {
            $schema = $bindings[0];

            return $this->data['functions'] ?? [];
        }

        // ── triggers ─────────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_trigger t')) {
            return $this->data['triggers'] ?? [];
        }

        // ── extensions ───────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_extension e')) {
            return $this->data['extensions'] ?? [];
        }

        // ── enums ────────────────────────────────────────────────────────
        if (str_contains($sql, 'JOIN pg_enum e')) {
            $schema = $bindings[0];
            $out = [];
            foreach ($this->data['enums'][$schema] ?? [] as $typname => $labels) {
                foreach ($labels as $label) {
                    $out[] = ['typname' => $typname, 'enumlabel' => $label];
                }
            }

            return $out;
        }

        // ── domains ──────────────────────────────────────────────────────
        if (str_contains($sql, "typtype = 'd'")) {
            $schema = $bindings[0];

            return $this->data['domains'][$schema] ?? [];
        }

        // ── partitions ───────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_inherits i')) {
            [$schema, $table] = $bindings;

            return $this->data['tables'][$schema][$table]['partitions'] ?? [];
        }

        // ── policies ─────────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_policy pol')) {
            [$schema, $table] = $bindings;

            return $this->data['tables'][$schema][$table]['policies'] ?? [];
        }

        // ── large objects ────────────────────────────────────────────────
        if (str_contains($sql, 'FROM pg_largeobject_metadata')) {
            return [['count' => 0, 'bytes' => 0]];
        }

        // ── aggregate counts (SELECT COUNT(*) FROM ...) ─────────────────
        if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+("[^"]+"|\w+)\.("[^"]+"|\w+)/i', $sql, $m)) {
            $rows = $this->extractRows($m[1], $m[2], $sql, $bindings);

            return [['count' => count($rows)]];
        }

        // ── data extraction (SELECT ... FROM "schema"."table" ...) ───────
        if (preg_match('/FROM\s+("[^"]+"|\w+)\.("[^"]+"|\w+)/i', $sql, $m)) {
            return $this->extractRows($m[1], $m[2], $sql, $bindings);
        }

        throw new \LogicException('fixture executor cannot route SQL: '.mb_substr($sql, 0, 80));
    }

    /** Apply keyset/limit semantics for data extraction queries. */
    protected function extractRows(string $schemaRaw, string $tableRaw, string $sql, array $bindings): array
    {
        $schema = trim($schemaRaw, '"');
        $table = trim($tableRaw, '"');
        $rows = $this->data['tables'][$schema][$table]['rows'] ?? [];

        // PDO pgsql wire fidelity: arrays arrive as PG literal text
        // ('{a,b}'), jsonb as JSON strings — the fixture matches that.
        $rows = array_map(fn ($row) => array_map(
            fn ($value) => is_array($value) ? '{'.implode(',', $value).'}' : $value,
            array_change_key_case($row, CASE_LOWER)
        ), $rows);

        // Column projection: SELECT "a", "b" FROM ...
        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s/is', $sql, $m) && ! str_contains(trim($m[1]), '*')) {
            $cols = array_map(
                fn ($c) => trim(trim($c), '"'),
                array_filter(array_map('trim', explode(',', $m[1])))
            );
            $rows = array_map(function ($row) use ($cols) {
                $out = [];
                foreach ($cols as $col) {
                    $out[$col] = $row[$col] ?? null;
                }

                return $out;
            }, $rows);
        }

        // Keyset predicate: WHERE ("pk1", "pk2") > (?, ?)
        if (preg_match('/WHERE\s+\(([^)]+)\)\s*>\s*\(\s*\?/i', $sql, $m)) {
            $pkCols = array_map(fn ($c) => trim(trim($c), '"'), explode(',', $m[1]));
            $rows = array_values(array_filter($rows, function ($row) use ($pkCols, $bindings) {
                foreach ($row as $name => $value) {
                    if (in_array($name, $pkCols, true)) {
                        $idx = array_search($name, $pkCols, true);
                        if ((string) $value <= (string) $bindings[$idx]) {
                            return false; // tuples compare lexicographically — single-col case covered
                        }
                    }
                }

                return true;
            }));
        }
        // LIMIT n
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) {
            $rows = array_slice($rows, 0, (int) $m[1]);
        }

        return $rows;
    }

    /** Defense in depth — mirrors PdoPgExecutor's read-only surface. */
    protected static function assertReadSql(string $sql): void
    {
        $head = ltrim(preg_replace('/\s+/', ' ', $sql) ?? '');
        foreach (['SELECT', 'SHOW', 'WITH'] as $allowed) {
            if (stripos($head, $allowed) === 0) {
                return;
            }
        }
        throw new \LogicException('postgres connector only issues read statements (30A): '.mb_substr($head, 0, 60));
    }
}
