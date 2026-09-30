<?php

namespace App\Services\ControlPlane\Migration\TargetAdapters;

use App\Services\ControlPlane\Migration\Contracts\TargetAdapter;
use PDO;

/**
 * PostgreSQL target adapter. Passwords come from vault refs resolved by the
 * caller — never embedded in stored config. Identifier names are strictly
 * validated (fail closed).
 */
class PostgresTargetAdapter implements TargetAdapter
{
    protected ?PDO $pdo = null;
    protected array $config;

    /** Column types per table (from ensureTable) — used to bind bytea values. */
    protected array $columnTypes = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=UTF8\'',
            $this->config['host'] ?? '127.0.0.1',
            (int) ($this->config['port'] ?? 5432),
            $this->config['database'] ?? 'postgres'
        );
        $this->pdo = new PDO($dsn, (string) ($this->config['username'] ?? 'postgres'), (string) ($this->config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }

    public function adapterId(): string
    {
        return 'postgres';
    }

    public function ensureTable(string $table, array $columns, array $primaryKey): void
    {
        $defs = [];
        foreach ($columns as $col) {
            $type = $this->mapType($col['type'] ?? 'text');
            $this->columnTypes[$table][$col['name']] = $type;
            $line = $this->qi($col['name']).' '.$type;
            if (! ($col['nullable'] ?? true)) {
                $line .= ' NOT NULL';
            }
            $defs[] = $line;
        }
        if ($primaryKey !== []) {
            $defs[] = 'PRIMARY KEY ('.implode(', ', array_map([$this, 'qi'], $primaryKey)).')';
        }
        $this->pdo->exec(sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $this->qi($table), implode(', ', $defs)));
    }

    public function applyForeignKeys(array $foreignKeys): void
    {
        foreach ($foreignKeys as $fk) {
            // Idempotent (35.5 live finding): re-runs/resumes hit an existing
            // constraint on the target — duplicate_object is swallowed.
            $this->pdo->exec(sprintf(
                "DO \$\$ BEGIN ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s);"
                ." EXCEPTION WHEN duplicate_object THEN NULL; END \$\$;",
                $this->qi($fk['table']),
                $this->qi('fk_'.$fk['table'].'_'.$fk['column']),
                $this->qi($fk['column']),
                $this->qi($fk['references_table']),
                $this->qi($fk['references_column'])
            ));
        }
    }

    public function ensureEnum(string $name, array $values): void
    {
        // Portable: enum-like values are enforced as CHECK via type mapping
        // callers; native PG enums are created when the adapter targets PG and
        // the type does not exist yet.
        $exists = $this->pdo->prepare("SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace WHERE t.typname = ? AND n.nspname = 'public'");
        $exists->execute([$name]);
        if ($exists->fetchColumn()) {
            return;
        }
        $quoted = implode(', ', array_map(fn ($v) => $this->pdo->quote((string) $v), $values));
        $this->pdo->exec(sprintf('CREATE TYPE %s AS ENUM (%s)', $this->qi($name), $quoted));
    }

    public function insertBatch(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        $colSql = implode(', ', array_map([$this, 'qi'], $columns));
        $placeholders = [];
        $bindings = [];
        foreach ($rows as $row) {
            $ph = [];
            foreach ($columns as $c) {
                $ph[] = '?';
                $value = $row[$c] ?? null;
                // PDO pgsql binds PHP false as an empty string — booleans must
                // be bound as PostgreSQL literal text (Phase 28 dogfood catch).
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $bindings[] = $this->bindValue($table, $c, $value);
            }
            $placeholders[] = '('.implode(', ', $ph).')';
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT DO NOTHING',
            $this->qi($table), $colSql, implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Phase 32G — idempotent CDC apply: true upsert on PK. Duplicate or
     * out-of-order replays converge; no event can corrupt target state.
     */
    public function upsertBatch(string $table, array $rows, array $primaryKey): int
    {
        if ($rows === []) {
            return 0;
        }
        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $colSql = implode(', ', array_map([$this, 'qi'], $columns));
                $ph = implode(', ', array_fill(0, count($columns), '?'));
                $nonPk = array_values(array_diff($columns, $primaryKey));
                $conflict = $primaryKey !== []
                    ? sprintf(' ON CONFLICT (%s) DO UPDATE SET %s',
                        implode(', ', array_map([$this, 'qi'], $primaryKey)),
                        implode(', ', array_map(fn ($c) => $this->qi($c).' = EXCLUDED.'.$this->qi($c), $nonPk)))
                    : ' ON CONFLICT DO NOTHING';
                $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)%s', $this->qi($table), $colSql, $ph, $conflict);
                $bindings = [];
                foreach ($columns as $c) {
                    $value = $row[$c] ?? null;
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    $bindings[] = $this->bindValue($table, $c, $value);
                }
                $this->pdo->prepare($sql)->execute($bindings);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $count;
    }

    /** Phase 32G — idempotent delete by PK (absent row → false, no error). */
    public function deleteByPk(string $table, array $pkRow): bool
    {
        $stmt = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE %s',
            $this->qi($table),
            implode(' AND ', array_map(fn ($p) => $this->qi($p).' = ?', array_keys($pkRow)))
        ));
        $stmt->execute(array_values($pkRow));

        return $stmt->rowCount() > 0;
    }

    public function truncateTable(string $table): void
    {
        // Reset semantics: a table that does not exist (real-mode run
        // against an operator-managed schema before ensureTable, or a
        // partial previous run) is already clean — 35.5 live finding: the
        // hard 42P01 here masked the actual run state.
        if (! $this->tableExists($table)) {
            return;
        }
        $this->pdo->exec('TRUNCATE TABLE '.$this->qi($table).' CASCADE');
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->qi($table))->fetchColumn();
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    public function setSequence(string $table, string $column, int $value): void
    {
        // PG identity/serial sequences are named <table>_<column>_seq by default.
        $seq = $this->pdo->prepare("SELECT pg_get_serial_sequence(?, ?)");
        $seq->execute([$table, $column]);
        $name = $seq->fetchColumn();
        if ($name) {
            $stmt = $this->pdo->prepare('SELECT setval(?, ?, true)');
            $stmt->execute([$name, $value]);
        }
    }

    public function sequenceValue(string $table, string $column): ?int
    {
        $seq = $this->pdo->prepare('SELECT pg_get_serial_sequence(?, ?)');
        $seq->execute([$table, $column]);
        $name = $seq->fetchColumn();
        if (! $name) {
            return null;
        }

        return (int) $this->pdo->query('SELECT last_value FROM '.$name)->fetchColumn();
    }

    public function orphanCount(string $table, string $column, string $refTable, string $refColumn): int
    {
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM %s c LEFT JOIN %s p ON p.%s = c.%s WHERE c.%s IS NOT NULL AND p.%s IS NULL',
            $this->qi($table), $this->qi($refTable), $this->qi($refColumn), $this->qi($column), $this->qi($column), $this->qi($refColumn)
        ));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function checksum(string $table, array $pkColumns, ?int $sampleEvery = null): string
    {
        $order = implode(', ', array_map([$this, 'qi'], $pkColumns ?: ['1']));
        $sql = 'SELECT * FROM '.$this->qi($table).' ORDER BY '.$order.($sampleEvery ? " WHERE 1=1 OFFSET 0" : '');
        $ctx = hash_init('sha256');
        $stmt = $this->pdo->query($sql);
        $i = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $i++;
            if ($sampleEvery && ($i % $sampleEvery) !== 1) {
                continue;
            }
            hash_update($ctx, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return hash_final($ctx);
    }

    public function distinctValues(string $table, string $column): array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT DISTINCT %s FROM %s WHERE %s IS NOT NULL ORDER BY 1', $this->qi($column), $this->qi($table), $this->qi($column)));
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    protected function mapType(string $type): string
    {
        $t = strtolower($type);
        $map = [
            'varchar' => 'text', 'bpchar' => 'text', 'bool' => 'boolean', 'int2' => 'smallint',
            'int4' => 'integer', 'int8' => 'bigint', 'float4' => 'real', 'float8' => 'double precision',
            'numeric' => 'numeric', 'bytea' => 'bytea', 'json' => 'json', 'jsonb' => 'jsonb',
            'timestamptz' => 'timestamptz', 'timestamp' => 'timestamp', 'date' => 'date', 'uuid' => 'uuid',
            'text' => 'text', '_text' => 'text[]', '_uuid' => 'uuid[]', '_int4' => 'integer[]', '_int8' => 'bigint[]',
        ];
        if (isset($map[$t])) {
            return $map[$t];
        }

        return $t; // enums and domain types pass through (created via ensureEnum)
    }

    /**
     * Bind a value for its target column type. bytea columns receive raw
     * binary from the extractors (MySQL BLOB/BINARY, PG bytea); PDO pgsql
     * sends bound strings as text in the connection encoding, so raw bytes
     * that are not valid UTF-8 crash the insert (22021). Encoding to the
     * canonical bytea hex literal makes the bound text a well-formed bytea
     * input — byte-exact on the server (35.5 live finding).
     */
    protected function bindValue(string $table, string $column, mixed $value): mixed
    {
        if ($value === null || ! is_string($value)) {
            return $value;
        }
        if (($this->columnTypes[$table][$column] ?? '') === 'bytea') {
            return '\x'.bin2hex($value);
        }

        return $value;
    }

    protected function qi(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
}
