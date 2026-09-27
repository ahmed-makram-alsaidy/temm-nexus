<?php

namespace App\Services\ControlPlane\Migration\SourceAdapters;

use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use PDO;

/**
 * SQLite source adapter — engine test fixture + local dry-run source.
 * Implements the same read-only contract as the Supabase adapter so the
 * whole engine (plan → run → validate → resume) is tested for real.
 */
class SqliteSourceAdapter extends BaseSourceAdapter
{

    public static function id(): string
    {
        return 'sqlite';
    }

    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }
        $path = $this->source->connection['path'] ?? ':memory:';
        $this->pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Read-only enforcement on SQLite: open a second read-only handle for
        // queries via query-only pragma.
        $this->pdo->exec('PRAGMA query_only = ON');
        $this->connection = ['schema' => 'public'];
    }

    protected function schemaExists(string $schema): bool
    {
        // SQLite fixtures emulate the supabase domain tables in `auth_users`,
        // `storage_buckets`, `storage_objects` plain tables.
        return false;
    }

    public function inventory(): array
    {
        $this->connect();

        return $this->transactionReadonly(function () {
            $inv = [];
            $inv['schemas'] = ['public'];
            $tables = [];
            $names = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            // Fixture convention: auth_* / storage_* tables are domain tables,
            // not app tables.
            $names = array_values(array_filter($names, fn ($n) => ! str_starts_with($n, 'auth_') && ! str_starts_with($n, 'storage_')));
            foreach ($names as $name) {
                $tables[] = $this->sqliteTable($name);
            }
            $inv['tables'] = $tables;
            $inv['views'] = $this->pdo->query("SELECT name, 'public' AS schema FROM sqlite_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $inv['matviews'] = [];
            $inv['enums'] = [];
            $inv['functions'] = [];
            $inv['triggers'] = [];
            $inv['policies'] = [];
            $inv['extensions'] = [];
            $inv['auth'] = $this->sqliteAuth();
            $inv['storage'] = $this->sqliteStorage();
            $inv['realtime'] = ['present' => false, 'publication_tables' => []];
            $inv['cron'] = [];
            $inv['edge_functions'] = (array) ($this->source->connection['manifest']['edge_functions'] ?? []);
            $inv['client_dependencies'] = (array) ($this->source->connection['manifest']['client_dependencies'] ?? []);

            return $inv;
        });
    }

    public function countRows(string $schema, string $table): int
    {
        $this->connect();

        return (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->qi($table))->fetchColumn();
    }

    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $cols = $columns === [] ? '*' : implode(', ', array_map([$this, 'qi'], $columns));
        $stmt = $this->pdo->query('SELECT '.$cols.' FROM '.$this->qi($table));
        $total = 0;
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $batch[] = $row;
            $total++;
            if (count($batch) >= $batchSize) {
                foreach ($batch as $b) {
                    $callback($b);
                }
                $batch = [];
            }
        }
        foreach ($batch as $b) {
            $callback($b);
        }

        return $total;
    }

    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $has = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='auth_users'")->fetchColumn();
        if (! $has) {
            return 0;
        }
        $stmt = $this->pdo->query('SELECT id, email, encrypted_password, created_at FROM auth_users ORDER BY id');
        $total = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $callback($row);
            $total++;
        }

        return $total;
    }

    protected function transactionReadonly(callable $fn)
    {
        return $fn(); // query_only pragma already guarantees read-only
    }

    protected function sqliteTable(string $name): array
    {
        $cols = [];
        $pk = [];
        $stmt = $this->pdo->prepare('PRAGMA table_info('.$this->qi($name).')');
        $stmt->execute();
        foreach ($stmt->fetchAll() as $c) {
            $cols[] = [
                'name' => $c['name'],
                'type' => $c['type'] ?: 'text',
                'nullable' => ! ((int) $c['notnull']),
                'default' => $c['dflt_value'],
            ];
            if ((int) $c['pk']) {
                $pk[] = $c['name'];
            }
        }
        $fks = [];
        $fkStmt = $this->pdo->prepare('PRAGMA foreign_key_list('.$this->qi($name).')');
        $fkStmt->execute();
        foreach ($fkStmt->fetchAll() as $fk) {
            $fks[] = [
                'column' => $fk['from'],
                'references_schema' => 'public',
                'references_table' => $fk['table'],
                'references_column' => $fk['to'] ?: 'id',
            ];
        }
        $indexes = [];
        $ixList = $this->pdo->prepare('PRAGMA index_list('.$this->qi($name).')');
        $ixList->execute();
        foreach ($ixList->fetchAll() as $ix) {
            if (! empty($ix['origin']) && $ix['origin'] === 'pk') {
                continue;
            }
            $colsStmt = $this->pdo->prepare('PRAGMA index_info('.$this->qi($ix['name']).')');
            $colsStmt->execute();
            $indexes[] = [
                'name' => $ix['name'],
                'unique' => (bool) $ix['unique'],
                'columns' => array_map(fn ($r) => $r['name'], $colsStmt->fetchAll()),
            ];
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM '.$this->qi($name))->fetchColumn();

        return [
            'schema' => 'public',
            'name' => $name,
            'columns' => $cols,
            'primary_key' => $pk,
            'foreign_keys' => $fks,
            'indexes' => $indexes,
            'rls_enabled' => false,
            'row_estimate' => $count,
        ];
    }

    protected function sqliteAuth(): array
    {
        $has = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='auth_users'")->fetchColumn();
        if (! $has) {
            return ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'not_supabase_auth'];
        }
        $users = (int) $this->pdo->query('SELECT COUNT(*) FROM auth_users')->fetchColumn();
        $sample = $this->pdo->query("SELECT encrypted_password FROM auth_users WHERE encrypted_password IS NOT NULL AND encrypted_password <> '' LIMIT 1")->fetchColumn();
        $strategy = is_string($sample) && str_starts_with($sample, '$2') ? 'bcrypt' : 'unknown';

        return ['present' => true, 'users_count' => $users, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => $strategy];
    }

    protected function sqliteStorage(): array
    {
        $hasB = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='storage_buckets'")->fetchColumn();
        if (! $hasB) {
            return ['present' => false, 'buckets' => [], 'object_counts' => []];
        }
        $buckets = $this->pdo->query('SELECT name, public FROM storage_buckets ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        $hasO = $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='storage_objects'")->fetchColumn();
        if ($hasO) {
            foreach ($this->pdo->query('SELECT bucket_id, COUNT(*) AS objects FROM storage_objects GROUP BY bucket_id')->fetchAll() as $r) {
                $counts[$r['bucket_id']] = ['objects' => (int) $r['objects'], 'bytes' => 0];
            }
        }

        return ['present' => true, 'buckets' => $buckets, 'object_counts' => $counts];
    }
}
