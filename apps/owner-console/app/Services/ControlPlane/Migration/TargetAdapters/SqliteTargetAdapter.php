<?php

namespace App\Services\ControlPlane\Migration\TargetAdapters;

use App\Services\ControlPlane\Migration\Contracts\TargetAdapter;
use PDO;

/**
 * SQLite target adapter — used by the engine test suite and disposable local
 * dry-runs. Same contract as the PostgreSQL target so the runner, resume and
 * validation logic are exercised for real (not mocked).
 */
class SqliteTargetAdapter implements TargetAdapter
{
    protected ?PDO $pdo = null;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }
        $path = $this->config['path'] ?? ':memory:';
        $fresh = $path !== ':memory:' && (! file_exists($path) || ($this->config['recreate'] ?? false));
        $this->pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        if ($fresh) {
            foreach ($this->existingTables() as $t) {
                $this->pdo->exec('DROP TABLE IF EXISTS '.$this->qi($t));
            }
        }
    }

    public function adapterId(): string
    {
        return 'sqlite';
    }

    public function ensureTable(string $table, array $columns, array $primaryKey): void
    {
        $defs = [];
        $seq = false;
        foreach ($columns as $col) {
            $type = $this->mapType($col['type'] ?? 'text');
            $line = $this->qi($col['name']).' '.$type;
            $isIntPk = in_array($col['name'], $primaryKey, true) && $type === 'INTEGER' && count($primaryKey) === 1;
            if ($isIntPk) {
                $line .= ' PRIMARY KEY AUTOINCREMENT';
                $seq = true;
            } else {
                if (! ($col['nullable'] ?? true)) {
                    $line .= ' NOT NULL';
                }
            }
            $defs[] = $line;
        }
        if ($primaryKey !== [] && ! $seq) {
            $defs[] = 'PRIMARY KEY ('.implode(', ', array_map([$this, 'qi'], $primaryKey)).')';
        }
        $this->pdo->exec(sprintf('CREATE TABLE IF NOT EXISTS %s (%s)', $this->qi($table), implode(', ', $defs)));
    }

    public function applyForeignKeys(array $foreignKeys): void
    {
        // SQLite cannot ALTER TABLE ADD CONSTRAINT; FK integrity on the sqlite
        // target is proven by the fk_orphans validator (query-based), which
        // does not rely on declared constraints.
    }

    public function ensureEnum(string $name, array $values): void
    {
        // SQLite has no enum types — enum columns degrade to text + CHECK,
        // which the schema builder applies at column level when told.
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
                $bindings[] = $row[$c] ?? null;
            }
            $placeholders[] = '('.implode(', ', $ph).')';
        }
        // Idempotent insert: IGNORE on conflict keeps resume + clean rerun safe.
        $sql = sprintf('INSERT OR IGNORE INTO %s (%s) VALUES %s', $this->qi($table), $colSql, implode(', ', $placeholders));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    public function truncateTable(string $table): void
    {
        $this->pdo->exec('DELETE FROM '.$this->qi($table));
        // sqlite_sequence only exists once an AUTOINCREMENT column was
        // created; resets on targets without one must not fail (28.1G).
        $hasSequenceTable = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();
        if ($hasSequenceTable) {
            $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name = ".$this->pdo->quote($table));
        }
    }

    public function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->qi($table))->fetchColumn();
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    public function setSequence(string $table, string $column, int $value): void
    {
        // sqlite_sequence only exists once an AUTOINCREMENT column was created
        // (Phase 28 — text/natural-key tables never have one; aligning a
        // sequence is then a no-op, not an error).
        $hasSequenceTable = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'")->fetchColumn();
        if (! $hasSequenceTable) {
            return;
        }
        // sqlite_sequence has no unique index on name — upsert manually.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sqlite_sequence WHERE name = ?');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() > 0) {
            $this->pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?')->execute([$value, $table]);
        } else {
            $this->pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)')->execute([$table, $value]);
        }
    }

    public function sequenceValue(string $table, string $column): ?int
    {
        $stmt = $this->pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
        $stmt->execute([$table]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
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
        $order = implode(', ', array_map([$this, 'qi'], $pkColumns ?: ['rowid']));
        $ctx = hash_init('sha256');
        $stmt = $this->pdo->query('SELECT * FROM '.$this->qi($table).' ORDER BY '.$order);
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
        if (in_array($t, ['int4', 'int2', 'smallint', 'integer'], true)) {
            return 'INTEGER';
        }
        if (in_array($t, ['int8', 'bigint'], true)) {
            return 'INTEGER';
        }
        if (in_array($t, ['bool', 'boolean'], true)) {
            return 'INTEGER';
        }
        if (in_array($t, ['float4', 'float8', 'real', 'double precision', 'numeric'], true)) {
            return 'REAL';
        }
        if (in_array($t, ['timestamptz', 'timestamp', 'date'], true)) {
            return 'TEXT';
        }

        return 'TEXT'; // uuid/json/jsonb/text/varchar/enums → TEXT (values preserved)
    }

    protected function existingTables(): array
    {
        return $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function qi(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
}
