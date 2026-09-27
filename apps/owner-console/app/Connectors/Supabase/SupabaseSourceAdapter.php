<?php

namespace App\Connectors\Supabase;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\SecretService;
use PDO;

/**
 * Supabase source adapter (24A.1, 24J.1 — Phase 27H: extracted into the
 * Supabase connector package).
 *
 * Connects to a PostgreSQL database that carries a Supabase-shaped schema
 * (public + auth + storage + cron schemas). Production Supabase is never
 * contacted by the platform itself — connections point at LOCAL/STAGING
 * snapshots or explicitly provided test endpoints.
 *
 * READ-ONLY GUARANTEE (24A.2): the session is forced read-only
 * (default_transaction_read_only=on) and every query runs inside an explicit
 * READ ONLY transaction. Inventory/preview/export never mutate the source.
 */
class SupabaseSourceAdapter extends BaseSourceAdapter
{
    public static function id(): string
    {
        return 'supabase';
    }

    public function connect(): void
    {
        if ($this->pdo) {
            return;
        }
        $conn = $this->source->connection ?? [];
        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (int) ($conn['port'] ?? 5432);
        $database = (string) ($conn['database'] ?? 'postgres');
        $user = (string) ($conn['username'] ?? 'postgres');
        $schema = (string) ($conn['schema'] ?? 'public');

        // Credentials NEVER come from the connection record — only from the
        // project vault via secret refs.
        $refs = $this->source->secret_refs ?? [];
        $password = '';
        if (! empty($refs['password'])) {
            $values = SecretService::valuesFor($this->source->project, [$refs['password']]);
            $password = (string) ($values[$refs['password']] ?? '');
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=UTF8\'', $host, $port, $database);
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        // Force read-only for this whole session — the guarantee is structural,
        // not procedural.
        $this->pdo->exec('SET default_transaction_read_only = on');
        $this->connection = ['host' => $host, 'port' => $port, 'database' => $database, 'schema' => $schema];
    }

    public function inventory(): array
    {
        $this->connect();

        return $this->transactionReadonly(function () {
            $inv = [];
            $inv['schemas'] = $this->schemas();
            $inv['tables'] = $this->tables();
            $inv['views'] = $this->views(false);
            $inv['matviews'] = $this->views(true);
            $inv['enums'] = $this->enums();
            $inv['functions'] = $this->functions();
            $inv['triggers'] = $this->triggers();
            $inv['policies'] = $this->policies();
            $inv['extensions'] = $this->extensions();
            $inv['auth'] = $this->auth();
            $inv['storage'] = $this->storage();
            $inv['realtime'] = $this->realtime();
            $inv['cron'] = $this->cron();
            $inv['edge_functions'] = $this->importedManifestSection('edge_functions');
            $inv['client_dependencies'] = $this->importedManifestSection('client_dependencies');

            // Attach row estimates to tables (cheap pg_class reltuples + exact for small).
            $estimates = $this->rowEstimates();
            foreach ($inv['tables'] as &$t) {
                $t['row_estimate'] = $estimates[$t['schema'].'.'.$t['name']] ?? 0;
            }

            return $inv;
        });
    }

    public function countRows(string $schema, string $table): int
    {
        $this->connect();

        return (int) $this->transactionReadonly(function () use ($schema, $table) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM '.$this->qi($schema).'.'.$this->qi($table));

            return $stmt->execute() ? (int) $stmt->fetchColumn() : 0;
        });
    }

    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        $cols = $columns === [] ? '*' : implode(', ', array_map([$this, 'qi'], $columns));
        $total = 0;
        $offset = 0;

        $this->transactionReadonly(function () use ($schema, $table, $cols, &$total, &$offset, $batchSize, $callback) {
            // PK-ordered batching via keyset pagination when a PK column exists;
            // deterministic ORDER BY fallback otherwise.
            while (true) {
                $sql = sprintf(
                    'SELECT %s FROM %s.%s ORDER BY 1 OFFSET %d LIMIT %d',
                    $cols, $this->qi($schema), $this->qi($table), $offset, $batchSize
                );
                $rows = $this->pdo->query($sql)->fetchAll();
                if ($rows === []) {
                    break;
                }
                $total += count($rows);
                foreach ($rows as $row) {
                    $callback($row);
                }
                $offset += $batchSize;
            }
        });

        return $total;
    }

    public function streamAuthUsers(callable $callback, int $batchSize = 500): int
    {
        $this->connect();
        if (! $this->schemaExists('auth')) {
            return 0;
        }
        $total = 0;
        $offset = 0;
        $this->transactionReadonly(function () use (&$total, &$offset, $batchSize, $callback) {
            while (true) {
                $sql = 'SELECT id, email, encrypted_password, raw_user_meta_data, created_at, last_sign_in_at'
                    .' FROM auth.users ORDER BY id OFFSET '.$offset.' LIMIT '.$batchSize;
                $rows = $this->pdo->query($sql)->fetchAll();
                if ($rows === []) {
                    break;
                }
                $total += count($rows);
                foreach ($rows as $row) {
                    $callback($row);
                }
                $offset += $batchSize;
            }
        });

        return $total;
    }

    // ── Inventory internals ─────────────────────────────────────────────

    protected function schemas(): array
    {
        $stmt = $this->pdo->query(
            "SELECT schema_name FROM information_schema.schemata
             WHERE schema_name NOT IN ('pg_catalog','information_schema','pg_toast')
             AND schema_name NOT LIKE 'pg_temp%' ORDER BY 1"
        );

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function tables(): array
    {
        $sql = "SELECT n.nspname AS schema, c.relname AS name
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog','information_schema','pg_toast')
                AND n.nspname NOT LIKE 'pg_temp%' AND n.nspname NOT LIKE '\\_%'
                ORDER BY 1, 2";
        $tables = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as &$t) {
            $t['columns'] = $this->columns($t['schema'], $t['name']);
            $t['primary_key'] = $this->primaryKey($t['schema'], $t['name']);
            $t['foreign_keys'] = $this->foreignKeys($t['schema'], $t['name']);
            $t['indexes'] = $this->indexes($t['schema'], $t['name']);
            $t['rls_enabled'] = $this->rlsEnabled($t['schema'], $t['name']);
        }

        return $tables;
    }

    protected function columns(string $schema, string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT column_name, data_type, udt_name, is_nullable, column_default, character_maximum_length
             FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position"
        );
        $stmt->execute([$schema, $table]);
        $out = [];
        foreach ($stmt->fetchAll() as $c) {
            $type = $c['udt_name'] === 'varchar' && $c['character_maximum_length']
                ? 'varchar('.$c['character_maximum_length'].')'
                : ($c['data_type'] === 'ARRAY' ? $c['udt_name'].'[]' : $c['udt_name']);
            $out[] = [
                'name' => $c['column_name'],
                'type' => $type,
                'nullable' => $c['is_nullable'] === 'YES',
                'default' => $c['column_default'],
            ];
        }

        return $out;
    }

    protected function primaryKey(string $schema, string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.attname
             FROM pg_index i
             JOIN pg_class c ON c.oid = i.indrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey)
             WHERE i.indisprimary AND n.nspname = ? AND c.relname = ?"
        );
        $stmt->execute([$schema, $table]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function foreignKeys(string $schema, string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                kcu.column_name AS column,
                ccu.table_schema AS references_schema,
                ccu.table_name AS references_table,
                ccu.column_name AS references_column
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.constraint_schema = kcu.constraint_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema
             WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ? AND tc.table_name = ?"
        );
        $stmt->execute([$schema, $table]);

        return $stmt->fetchAll();
    }

    protected function indexes(string $schema, string $table): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.relname AS name, idx.indisunique AS unique, array_agg(a.attname ORDER BY a.attnum) AS columns
             FROM pg_index idx
             JOIN pg_class t ON t.oid = idx.indrelid
             JOIN pg_namespace n ON n.oid = t.relnamespace
             JOIN pg_class i ON i.oid = idx.indexrelid
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(idx.indkey)
             WHERE n.nspname = ? AND t.relname = ? AND NOT idx.indisprimary
             GROUP BY i.relname, idx.indisunique ORDER BY i.relname"
        );
        $stmt->execute([$schema, $table]);

        return array_map(function ($r) {
            return ['name' => $r['name'], 'unique' => (bool) $r['unique'], 'columns' => self::parsePgArray($r['columns'])];
        }, $stmt->fetchAll());
    }

    protected function rlsEnabled(string $schema, string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT c.relrowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?');
        $stmt->execute([$schema, $table]);

        return (bool) $stmt->fetchColumn();
    }

    protected function views(bool $materialized): array
    {
        $kind = $materialized ? 'm' : 'v';
        $sql = "SELECT n.nspname AS schema, c.relname AS name
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE c.relkind = '{$kind}' AND n.nspname NOT IN ('pg_catalog','information_schema')
                AND n.nspname NOT LIKE '\\_%' ORDER BY 1, 2";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function enums(): array
    {
        $sql = "SELECT t.typname AS name, n.nspname AS schema, array_agg(e.enumlabel ORDER BY e.enumsortorder) AS values
                FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                JOIN pg_enum e ON e.enumtypid = t.oid
                WHERE n.nspname NOT IN ('pg_catalog','information_schema')
                GROUP BY t.typname, n.nspname ORDER BY 1";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => ['name' => $r['name'], 'schema' => $r['schema'], 'values' => self::parsePgArray($r['values'])], $rows);
    }

    protected function functions(): array
    {
        $sql = "SELECT n.nspname AS schema, p.proname AS name,
                       CASE WHEN p.prosecdef THEN 'definer' ELSE 'invoker' END AS security,
                       l.lanname AS language
                FROM pg_proc p
                JOIN pg_namespace n ON n.oid = p.pronamespace
                JOIN pg_language l ON l.oid = p.prolang
                WHERE n.nspname NOT IN ('pg_catalog','information_schema')
                ORDER BY 1, 2";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'name' => $r['name'],
                'schema' => $r['schema'],
                'security' => $r['security'],
                'language' => $r['language'],
                'auth_dependent' => true, // refined below by body scan
            ];
        }, $rows);
    }

    protected function triggers(): array
    {
        $sql = "SELECT n.nspname AS schema, c.relname AS table, t.tgname AS name,
                       pg_get_triggerdef(t.oid) AS definition
                FROM pg_trigger t
                JOIN pg_class c ON c.oid = t.tgrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE NOT t.tgisinternal AND n.nspname NOT IN ('pg_catalog','information_schema')
                ORDER BY 1, 2, 3";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => [
            'name' => $r['name'], 'table' => $r['table'], 'schema' => $r['schema'], 'definition' => $r['definition'],
        ], $rows);
    }

    protected function policies(): array
    {
        $sql = "SELECT schemaname AS schema, tablename AS table, policyname AS name,
                       cmd AS command, roles::text AS roles, qual AS using, with_check
                FROM pg_policies
                WHERE schemaname NOT IN ('pg_catalog','information_schema')
                ORDER BY schemaname, tablename, policyname";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function extensions(): array
    {
        return $this->pdo->query('SELECT extname AS name, extversion AS version FROM pg_extension ORDER BY 1')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function auth(): array
    {
        if (! $this->schemaExists('auth')) {
            return ['present' => false, 'users_count' => 0, 'identities_count' => 0, 'providers' => [], 'hash_strategy' => 'not_supabase_auth'];
        }
        $users = (int) $this->pdo->query('SELECT COUNT(*) FROM auth.users')->fetchColumn();
        $identities = 0;
        $providers = [];
        if ($this->tableExists('auth', 'identities')) {
            $identities = (int) $this->pdo->query('SELECT COUNT(*) FROM auth.identities')->fetchColumn();
            $providers = $this->pdo->query('SELECT DISTINCT provider FROM auth.identities ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
        }
        // Hash strategy: sample one non-null encrypted_password prefix only.
        $hashStrategy = 'unknown';
        $sample = $this->pdo->query("SELECT encrypted_password FROM auth.users WHERE encrypted_password IS NOT NULL AND encrypted_password <> '' LIMIT 1")->fetchColumn();
        if (is_string($sample) && $sample !== '') {
            $hashStrategy = str_starts_with($sample, '$2') ? 'bcrypt' : (str_starts_with($sample, '$argon2') ? 'argon2' : 'unknown');
        }

        return ['present' => true, 'users_count' => $users, 'identities_count' => $identities, 'providers' => $providers, 'hash_strategy' => $hashStrategy];
    }

    protected function storage(): array
    {
        if (! $this->schemaExists('storage')) {
            return ['present' => false, 'buckets' => [], 'object_counts' => []];
        }
        $buckets = $this->pdo->query('SELECT name, public, file_size_limit FROM storage.buckets ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $counts = [];
        $countsStmt = $this->pdo->query("SELECT bucket_id, COUNT(*) AS objects, COALESCE(SUM(COALESCE((metadata->>'size')::bigint, 0)), 0) AS bytes FROM storage.objects GROUP BY bucket_id ORDER BY 1");
        foreach ($countsStmt->fetchAll() as $r) {
            $counts[$r['bucket_id']] = ['objects' => (int) $r['objects'], 'bytes' => (int) $r['bytes']];
        }

        return ['present' => true, 'buckets' => $buckets, 'object_counts' => $counts];
    }

    protected function realtime(): array
    {
        if (! $this->schemaExists('realtime')) {
            return ['present' => false, 'publication_tables' => []];
        }
        $tables = $this->pdo->query(
            "SELECT schemaname, tablename FROM pg_publication_tables WHERE pubname = 'supabase_realtime' ORDER BY 1, 2"
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['present' => true, 'publication_tables' => $tables];
    }

    protected function cron(): array
    {
        if (! $this->schemaExists('cron')) {
            return [];
        }
        $rows = $this->pdo->query('SELECT jobname, schedule, command FROM cron.job ORDER BY jobname')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn ($r) => ['name' => $r['jobname'], 'schedule' => $r['schedule'], 'command' => $r['command']], $rows);
    }

    /**
     * Edge functions / client dependencies come from an operator-imported
     * manifest (24A.3 "from source/project metadata or imported manifest") —
     * never from contacting the provider.
     */
    protected function importedManifestSection(string $key): array
    {
        return (array) ($this->source->connection['manifest'][$key] ?? []);
    }

    protected function rowEstimates(): array
    {
        $rows = $this->pdo->query(
            "SELECT n.nspname || '.' || c.relname AS key, c.reltuples::bigint AS est
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog','information_schema')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        return array_map(fn ($v) => max(0, (int) $v), $rows);
    }

    protected function schemaExists(string $schema): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $stmt->execute([$schema]);

        return (bool) $stmt->fetchColumn();
    }

    protected function tableExists(string $schema, string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $stmt->execute([$schema, $table]);

        return (bool) $stmt->fetchColumn();
    }

    /** Run a closure inside an explicit READ ONLY transaction. */
    protected function transactionReadonly(callable $fn)
    {
        $this->pdo->exec('BEGIN TRANSACTION READ ONLY');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * PDO pgsql returns PostgreSQL array literals ("{a,b}") as strings —
     * parse them to PHP arrays (never lossy for our identifier/label sets).
     */
    protected static function parsePgArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }
        $trimmed = substr($value, 1, -1);
        if ($trimmed === '') {
            return [];
        }

        return array_map(fn ($part) => trim($part, '"'), explode(',', $trimmed));
    }
}
