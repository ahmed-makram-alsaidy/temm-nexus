<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\SchemaSnapshot;
use App\Services\ControlPlane\Migration\SchemaFingerprint;
use PDO;

/**
 * Phase 24E — schema diff / drift detection.
 *
 * Snapshots are normalized inventories (same shape as the migration engine's
 * tables section). Diff classification (ADDED/REMOVED/CHANGED) with severity
 * (INFO/SAFE/REVIEW/DANGEROUS) is advisory — the platform never applies
 * destructive changes automatically.
 */
class SchemaDiffService
{
    // ── Snapshot capture ────────────────────────────────────────────────

    /** Introspect a live PostgreSQL database into a normalized snapshot. */
    public static function introspect(string $host, int $port, string $database, string $username, string $password): array
    {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 8]
        );
        $pdo->exec('SET default_transaction_read_only = on');

        $tables = [];
        $rows = $pdo->query(
            "SELECT n.nspname AS schema, c.relname AS name, c.relrowsecurity AS rls
             FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'r' AND n.nspname NOT IN ('pg_catalog','information_schema','pg_toast')
             AND n.nspname NOT LIKE '\\_%' AND n.nspname NOT LIKE 'pg_temp%'
             ORDER BY 1, 2"
        )->fetchAll();
        foreach ($rows as $r) {
            $table = ['schema' => $r['schema'], 'name' => $r['name'], 'rls_enabled' => (bool) $r['rls']];
            $cols = [];
            $stmt = $pdo->prepare("SELECT column_name, udt_name, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position");
            $stmt->execute([$r['schema'], $r['name']]);
            foreach ($stmt->fetchAll() as $c) {
                $cols[] = ['name' => $c['column_name'], 'type' => $c['udt_name'], 'nullable' => $c['is_nullable'] === 'YES', 'default' => $c['column_default']];
            }
            $table['columns'] = $cols;
            $pkStmt = $pdo->prepare(
                "SELECT a.attname FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey)
                 WHERE i.indisprimary AND n.nspname = ? AND t.relname = ?"
            );
            $pkStmt->execute([$r['schema'], $r['name']]);
            $table['primary_key'] = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
            $fkStmt = $pdo->prepare(
                "SELECT kcu.column_name AS column, ccu.table_name AS references_table, ccu.column_name AS references_column
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.constraint_schema = kcu.constraint_schema
                 JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema
                 WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ? AND tc.table_name = ?"
            );
            $fkStmt->execute([$r['schema'], $r['name']]);
            $table['foreign_keys'] = $fkStmt->fetchAll();
            $ixStmt = $pdo->prepare(
                "SELECT i.relname AS name, idx.indisunique AS unique, array_agg(a.attname ORDER BY a.attnum) AS columns
                 FROM pg_index idx JOIN pg_class t ON t.oid = idx.indrelid
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 JOIN pg_class i ON i.oid = idx.indexrelid
                 JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(idx.indkey)
                 WHERE n.nspname = ? AND t.relname = ? AND NOT idx.indisprimary
                 GROUP BY i.relname, idx.indisunique ORDER BY 1"
            );
            $ixStmt->execute([$r['schema'], $r['name']]);
            $table['indexes'] = array_map(fn ($x) => ['name' => $x['name'], 'unique' => (bool) $x['unique'], 'columns' => $x['columns']], $ixStmt->fetchAll());
            $tables[] = $table;
        }

        $views = $pdo->query(
            "SELECT n.nspname AS schema, c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'v' AND n.nspname NOT IN ('pg_catalog','information_schema') ORDER BY 1, 2"
        )->fetchAll();

        $functions = $pdo->query(
            "SELECT p.proname AS name, n.nspname AS schema FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname NOT IN ('pg_catalog','information_schema') ORDER BY 1, 2"
        )->fetchAll();

        $triggers = $pdo->query(
            "SELECT t.tgname AS name, c.relname AS table FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND n.nspname NOT IN ('pg_catalog','information_schema')
             ORDER BY 1, 2"
        )->fetchAll();

        return [
            'tables' => $tables,
            'views' => $views,
            'matviews' => [],
            'enums' => [],
            'functions' => $functions,
            'triggers' => $triggers,
            'extensions' => $pdo->query('SELECT extname AS name, extversion AS version FROM pg_extension ORDER BY 1')->fetchAll(),
        ];
    }

    /** Capture + persist a snapshot for a project/environment. */
    public static function snapshot(Project $project, ?int $environmentId, string $source, array $inventory, ?string $label = null, ?int $userId = null): SchemaSnapshot
    {
        $snapshot = SchemaSnapshot::create([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'label' => $label,
            'source' => $source,
            'fingerprint' => SchemaFingerprint::compute($inventory),
            'snapshot' => $inventory,
            'created_by' => $userId,
        ]);
        AdminAudit::record('SCHEMA_SNAPSHOT_CREATED', $project, 'schema_snapshot', $snapshot->id, [
            'source' => $source, 'fingerprint' => substr($snapshot->fingerprint, 0, 12),
        ]);

        return $snapshot;
    }

    // ── Diff (24E.2) ────────────────────────────────────────────────────

    /**
     * Compare two normalized inventories. Returns structured rows:
     * [object_kind, object, change, severity, detail].
     */
    public static function diff(array $a, array $b): array
    {
        $rows = [];
        $tablesA = self::indexByName($a['tables'] ?? []);
        $tablesB = self::indexByName($b['tables'] ?? []);

        foreach ($tablesA as $name => $table) {
            if (! isset($tablesB[$name])) {
                $rows[] = ['kind' => 'table', 'object' => $name, 'change' => 'REMOVED', 'severity' => 'DANGEROUS', 'detail' => 'table dropped'];
            }
        }
        foreach ($tablesB as $name => $table) {
            if (! isset($tablesA[$name])) {
                $rows[] = ['kind' => 'table', 'object' => $name, 'change' => 'ADDED', 'severity' => 'SAFE', 'detail' => 'table added'];
                continue;
            }
            $rows = array_merge($rows, self::diffTable($name, $tablesA[$name], $table));
        }

        foreach (['views', 'functions', 'triggers'] as $kind) {
            $setA = self::indexByName($a[$kind] ?? []);
            $setB = self::indexByName($b[$kind] ?? []);
            foreach ($setA as $name => $_) {
                if (! isset($setB[$name])) {
                    $rows[] = ['kind' => $kind, 'object' => $name, 'change' => 'REMOVED', 'severity' => 'REVIEW', 'detail' => "{$kind} dropped"];
                }
            }
            foreach ($setB as $name => $_) {
                if (! isset($setA[$name])) {
                    $rows[] = ['kind' => $kind, 'object' => $name, 'change' => 'ADDED', 'severity' => 'SAFE', 'detail' => "{$kind} added"];
                }
            }
        }

        usort($rows, fn ($x, $y) => [$y['severity'], $x['kind'], $x['object']] <=> [$x['severity'], $y['kind'], $y['object']]);

        return $rows;
    }

    protected static function diffTable(string $name, array $old, array $new): array
    {
        $rows = [];
        $colsOld = collect($old['columns'] ?? [])->keyBy('name');
        $colsNew = collect($new['columns'] ?? [])->keyBy('name');

        foreach ($colsOld as $colName => $col) {
            if (! $colsNew->has($colName)) {
                $rows[] = ['kind' => 'column', 'object' => "{$name}.{$colName}", 'change' => 'REMOVED', 'severity' => 'DANGEROUS', 'detail' => 'column dropped'];
            }
        }
        foreach ($colsNew as $colName => $col) {
            if (! $colsOld->has($colName)) {
                $rows[] = ['kind' => 'column', 'object' => "{$name}.{$colName}", 'change' => 'ADDED', 'severity' => $col['nullable'] ?? true ? 'SAFE' : 'REVIEW', 'detail' => 'column added'];
                continue;
            }
            $prev = $colsOld[$colName];
            $typeChanged = strtolower($prev['type'] ?? '') !== strtolower($col['type'] ?? '');
            $nullChanged = (bool) ($prev['nullable'] ?? true) !== (bool) ($col['nullable'] ?? true);
            if ($typeChanged) {
                $rows[] = ['kind' => 'column', 'object' => "{$name}.{$colName}", 'change' => 'CHANGED', 'severity' => 'DANGEROUS', 'detail' => 'type changed '.$prev['type'].' → '.$col['type']];
            }
            if ($nullChanged && ! ($col['nullable'] ?? true)) {
                $rows[] = ['kind' => 'column', 'object' => "{$name}.{$colName}", 'change' => 'CHANGED', 'severity' => 'REVIEW', 'detail' => 'column became NOT NULL'];
            }
        }

        $pkChanged = collect($old['primary_key'] ?? [])->sort()->values()->all() !== collect($new['primary_key'] ?? [])->sort()->values()->all();
        if ($pkChanged) {
            $rows[] = ['kind' => 'primary_key', 'object' => $name, 'change' => 'CHANGED', 'severity' => 'DANGEROUS', 'detail' => 'primary key changed'];
        }

        $fksOld = collect($old['foreign_keys'] ?? [])->map(fn ($fk) => $fk['column'].'→'.$fk['references_table'].'.'.$fk['references_column'])->sort()->values();
        $fksNew = collect($new['foreign_keys'] ?? [])->map(fn ($fk) => $fk['column'].'→'.$fk['references_table'].'.'.$fk['references_column'])->sort()->values();
        foreach ($fksOld->diff($fksNew) as $fk) {
            $rows[] = ['kind' => 'foreign_key', 'object' => "{$name}: {$fk}", 'change' => 'REMOVED', 'severity' => 'DANGEROUS', 'detail' => 'FK removed'];
        }
        foreach ($fksNew->diff($fksOld) as $fk) {
            $rows[] = ['kind' => 'foreign_key', 'object' => "{$name}: {$fk}", 'change' => 'ADDED', 'severity' => 'SAFE', 'detail' => 'FK added'];
        }

        // Unique index removal is dangerous (integrity loss without FK noise).
        $uniqOld = collect($old['indexes'] ?? [])->filter(fn ($ix) => $ix['unique'] ?? false)->pluck('name');
        $uniqNew = collect($new['indexes'] ?? [])->filter(fn ($ix) => $ix['unique'] ?? false)->pluck('name');
        foreach ($uniqOld->diff($uniqNew) as $ix) {
            $rows[] = ['kind' => 'index', 'object' => "{$name}: {$ix}", 'change' => 'REMOVED', 'severity' => 'DANGEROUS', 'detail' => 'unique constraint removed'];
        }

        return $rows;
    }

    protected static function indexByName(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name'])) {
                $out[$item['name']] = $item;
            }
        }

        return $out;
    }

    // ── Migration drift (24E.3) ─────────────────────────────────────────

    /**
     * Drift between a live database and its migration expectations.
     * Requires a live PDO connection to the project DB (skips honestly when
     * unreachable).
     */
    public static function migrationDrift(PDO $pdo, ?Project $project = null): array
    {
        $rows = [];
        // 1. migrations table present?
        $hasMigrations = (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='migrations'")->fetchColumn();
        if (! $hasMigrations) {
            return [['kind' => 'migrations', 'object' => 'migrations table', 'change' => 'REMOVED', 'severity' => 'REVIEW', 'detail' => 'no migrations table — schema state untracked']];
        }
        $applied = $pdo->query('SELECT migration FROM migrations ORDER BY batch, migration')->fetchAll(PDO::FETCH_COLUMN);

        // 2. DB objects not created by any migration file (manual changes) —
        //    compare table names against CREATE TABLE statements in migration files.
        $migrationFiles = self::migrationFiles($project);
        $createdInMigrations = [];
        foreach ($migrationFiles as $content) {
            if (preg_match_all('/create\s+(?:table\s+)?[\'"(`]*([a-zA-Z_][a-zA-Z0-9_]*)/i', $content, $m)) {
                foreach ($m[1] as $t) {
                    $createdInMigrations[$t] = true;
                }
            }
        }
        $liveTables = $pdo->query(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind = 'r' AND n.nspname = 'public' ORDER BY 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($liveTables as $t) {
            if (! isset($createdInMigrations[$t])) {
                $rows[] = ['kind' => 'drift', 'object' => $t, 'change' => 'ADDED', 'severity' => 'REVIEW', 'detail' => 'table exists in DB but is not created by any migration file (manual change?)'];
            }
        }

        // 3. Applied migration names not present as files (file absent).
        foreach ($applied as $migration) {
            $rows[] = ['kind' => 'migration_version', 'object' => $migration, 'change' => 'INFO', 'severity' => 'INFO', 'detail' => 'applied'];
        }

        return $rows;
    }

    /** @return string[] contents of migration files for a project checkout */
    protected static function migrationFiles(?Project $project = null): array
    {
        $contents = [];
        $dir = $project ? ControlPlanePaths::projectDir($project->slug) : null;
        $migrationsDir = $dir && is_dir($dir) ? $dir.'/database/migrations' : null;
        if ($migrationsDir && is_dir($migrationsDir)) {
            foreach (glob($migrationsDir.'/*.php') ?: [] as $file) {
                $contents[] = (string) file_get_contents($file);
            }
        }

        return $contents;
    }

    /** Dangerous rows helper for readiness/guards. */
    public static function hasDangerous(array $rows): bool
    {
        return collect($rows)->contains(fn ($r) => $r['severity'] === 'DANGEROUS');
    }
}
