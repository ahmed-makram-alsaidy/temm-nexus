<?php

namespace App\Connectors\Mysql\Protocol;

/**
 * Phase 31F — in-memory executor serving the synthetic MySQL fixture.
 *
 * Interprets the dataset into information_schema-shaped result rows so every
 * catalog query, type mapping and extraction path is exercised against
 * realistic metadata with zero network I/O. Read-only by construction.
 */
class FixtureMysqlExecutor implements MysqlExecutor
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

        if (str_contains($sql, 'SELECT VERSION()')) {
            return [['VERSION()' => $this->data['server_version']]];
        }
        if (str_contains($sql, 'SHOW VARIABLES')) {
            $vars = $this->data['server_variables'] ?? [];
            $out = [];
            foreach ($vars as $name => $value) {
                $out[] = ['variable_name' => $name, 'value' => $value];
            }

            return $out;
        }
        if (str_contains($sql, '@@SESSION.transaction_read_only')) {
            return [['@@SESSION.transaction_read_only' => '1']];
        }
        if (str_contains($sql, 'FROM information_schema.tables')) {
            $database = $bindings[0] ?? $this->data['database'];
            $out = [];
            foreach ($this->data['tables'] as $name => $def) {
                $out[] = [
                    'table_name' => $name,
                    'engine' => $def['engine'],
                    'table_collation' => $def['table_collation'],
                    'auto_increment' => $def['auto_increment'],
                    'table_rows' => $def['table_rows'],
                    'table_comment' => '',
                ];
            }

            return $out;
        }
        if (str_contains($sql, 'FROM information_schema.columns')) {
            [$database, $table] = $bindings;

            return $this->data['tables'][$table]['columns'] ?? [];
        }
        if (str_contains($sql, "constraint_name = 'PRIMARY'")) {
            $table = $bindings[1];

            return array_map(
                fn ($col) => ['column_name' => $col],
                $this->data['tables'][$table]['primary_key'] ?? []
            );
        }
        if (str_contains($sql, 'FROM information_schema.key_column_usage')) {
            $table = $bindings[1];

            return $this->data['tables'][$table]['foreign_keys'] ?? [];
        }
        if (str_contains($sql, 'FROM information_schema.statistics')) {
            $table = $bindings[1];

            return $this->data['tables'][$table]['indexes'] ?? [];
        }
        if (str_contains($sql, 'FROM information_schema.views')) {
            // The catalog query aliases table_name → view_name (real behavior).
            return array_map(fn ($view) => [
                'view_name' => $view['table_name'],
                'view_definition' => $view['view_definition'],
            ], $this->data['views'] ?? []);
        }
        if (str_contains($sql, 'FROM information_schema.triggers')) {
            return $this->data['triggers'] ?? [];
        }
        if (str_contains($sql, 'FROM information_schema.routines')) {
            return $this->data['routines'] ?? [];
        }
        if (str_contains($sql, 'FROM information_schema.events')) {
            return $this->data['events'] ?? [];
        }

        // Aggregate counts.
        if (preg_match('/SELECT\s+COUNT\(\*\)\s+FROM\s+`?(\w+)`?\.`?(\w+)`?/i', $sql, $m)) {
            $rows = $this->extractRows($m[1], $m[2], $sql, $bindings);

            return [['count' => count($rows)]];
        }

        // Data extraction: SELECT ... FROM `db`.`table` ...
        if (preg_match('/FROM\s+`?(\w+)`?\.`?(\w+)`?/i', $sql, $m)) {
            return $this->extractRows($m[1], $m[2], $sql, $bindings);
        }

        throw new \LogicException('fixture executor cannot route SQL: '.mb_substr($sql, 0, 80));
    }

    /** Apply keyset/limit semantics for data extraction queries. */
    protected function extractRows(string $database, string $table, string $sql, array $bindings): array
    {
        $rows = array_map(
            fn ($row) => array_change_key_case($row, CASE_LOWER),
            $this->data['tables'][$table]['rows'] ?? []
        );

        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s/is', $sql, $m) && ! str_contains(trim($m[1]), '*')) {
            $cols = array_map(fn ($c) => trim(trim($c), '`'), array_filter(array_map('trim', explode(',', $m[1]))));
            $rows = array_map(function ($row) use ($cols) {
                $out = [];
                foreach ($cols as $col) {
                    $out[$col] = $row[$col] ?? null;
                }

                return $out;
            }, $rows);
        }

        if (preg_match('/WHERE\s+\(([^)]+)\)\s*>\s*\(\s*\?/i', $sql, $m)) {
            $pkCols = array_map(fn ($c) => trim(trim($c), '`'), explode(',', $m[1]));
            $rows = array_values(array_filter($rows, function ($row) use ($pkCols, $bindings) {
                foreach ($pkCols as $i => $col) {
                    if ((string) ($row[$col] ?? '') <= (string) $bindings[$i]) {
                        return false;
                    }
                }

                return true;
            }));
        }

        if (preg_match('/LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $sql, $m)) {
            $offset = (int) ($m[2] ?? 0);
            $rows = array_slice($rows, $offset, (int) $m[1]);
        }

        return $rows;
    }

    /** Defense in depth — mirrors PdoMysqlExecutor's read-only surface. */
    protected static function assertReadSql(string $sql): void
    {
        $head = ltrim(preg_replace('/\s+/', ' ', $sql) ?? '');
        foreach (['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN'] as $allowed) {
            if (stripos($head, $allowed) === 0) {
                return;
            }
        }
        throw new \LogicException('mysql connector only issues read statements (31): '.mb_substr($head, 0, 60));
    }
}
