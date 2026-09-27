<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20R advanced database tooling on ONE project DB: ERD graph data,
 * index inventory + safe creation, trigger inventory + controlled
 * create/delete, extension inventory + allowlisted install attempts,
 * authorization display (roles, RLS, Laravel mapping note).
 */
class DbAdvancedService
{
    public const EXTENSION_ALLOWLIST = [
        'pg_stat_statements' => 'Query performance statistics',
        'pgcrypto' => 'Cryptographic functions',
        'uuid-ossp' => 'UUID generation',
        'pg_trgm' => 'Trigram similarity search',
        'citext' => 'Case-insensitive text',
    ];

    /** @return array{tables:list<string>,edges:list<array{from:string,from_col:string,to:string,to_col:string}>} */
    public static function erd(Project $project): array
    {
        $explorer = ProjectDatabaseExplorer::for($project);
        $tables = array_values(array_filter(
            array_column($explorer->tables(), 'name'),
            fn ($t) => ! ProtectedTables::isReadOnly($t)
        ));
        $tables = array_slice($tables, 0, 30);
        $conn = ProjectConnectionManager::connection($project);
        $edges = array_map(fn ($r) => [
            'from' => $r->from_table, 'from_col' => $r->from_column,
            'to' => $r->to_table, 'to_col' => $r->to_column,
        ], DB::connection($conn)->select(
            "SELECT tc.table_name AS from_table, kcu.column_name AS from_column,
                    ccu.table_name AS to_table, ccu.column_name AS to_column
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
               JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
              WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
              ORDER BY tc.table_name"
        ));

        return ['tables' => $tables, 'edges' => $edges];
    }

    public static function erdSvg(array $graph): string
    {
        $tables = $graph['tables'];
        $perCol = 6;
        $w = 220;
        $h = 44;
        $gapX = 260;
        $gapY = 60;
        $cols = (int) ceil(count($tables) / $perCol);
        $viewW = 10 + max(1, $cols) * $gapX;
        $viewH = 10 + max(1, min($perCol, count($tables) ?: 1)) * $gapY;
        $pos = [];
        foreach ($tables as $i => $t) {
            $pos[$t] = ['x' => 10 + intdiv($i, $perCol) * $gapX, 'y' => 10 + ($i % $perCol) * $gapY];
        }
        $svg = '<svg viewBox="0 0 '.$viewW.' '.$viewH.'" style="width:100%;max-width:'.$viewW.'px;height:auto;background:var(--cp-surface);border:1px solid var(--cp-border);border-radius:.625rem" role="img" aria-label="Entity relationship diagram">';
        $svg .= '<defs><marker id="cp-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8" fill="none" stroke="#818cf8" stroke-width="1.5"/></marker></defs>';
        foreach ($graph['edges'] as $e) {
            if (! isset($pos[$e['from']]) || ! isset($pos[$e['to']])) {
                continue;
            }
            $a = $pos[$e['from']];
            $b = $pos[$e['to']];
            $x1 = $a['x'] + $w;
            $y1 = $a['y'] + $h / 2;
            $x2 = $b['x'];
            $y2 = $b['y'] + $h / 2;
            $svg .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="#818cf8" stroke-width="1.2" marker-end="url(#cp-arrow)" opacity="0.7"/>';
        }
        foreach ($pos as $name => $p) {
            $svg .= '<g><rect x="'.$p['x'].'" y="'.$p['y'].'" width="'.$w.'" height="'.$h.'" rx="8" fill="#1a1a1f" stroke="#34343c"/>'
                .'<text x="'.($p['x'] + 12).'" y="'.($p['y'] + 27).'" fill="#f4f4f5" font-size="13" font-family="monospace">'.htmlspecialchars(mb_substr($name, 0, 28)).'</text></g>';
        }

        return $svg.'</svg>';
    }

    /** @return list<array{table:string,name:string,definition:string,size:string,scans:int}> */
    public static function indexes(Project $project): array
    {
        $conn = ProjectConnectionManager::connection($project);

        return array_map(fn ($r) => [
            'table' => $r->table, 'name' => $r->name, 'definition' => $r->definition,
            'size' => $r->size, 'scans' => (int) $r->scans,
        ], DB::connection($conn)->select(
            "SELECT t.tablename AS \"table\", t.indexname AS name, t.indexdef AS definition,
                    pg_size_pretty(pg_relation_size(quote_ident(t.schemaname)||'.'||quote_ident(t.indexname))) AS size,
                    COALESCE(s.idx_scan, 0) AS scans
               FROM pg_indexes t
               LEFT JOIN pg_stat_user_indexes s ON s.indexrelname = t.indexname AND s.schemaname = t.schemaname
              WHERE t.schemaname = 'public'
              ORDER BY t.tablename, t.indexname"
        ));
    }

    public static function createIndex(Project $project, string $table, array $columns, bool $unique): string
    {
        $table = DdlService::identifier($table);
        DdlService::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        $available = array_column($explorer->columns($table), 'name');
        $columns = array_values(array_unique($columns));
        abort_if($columns === [] || count($columns) > 5, 422, 'Select 1–5 columns.');
        foreach ($columns as $c) {
            DdlService::identifier($c);
            abort_unless(in_array($c, $available, true), 422, "Unknown column: {$c}");
        }
        $name = 'idx_'.mb_substr($table.'_'.implode('_', $columns), 0, 50);
        $quoted = implode(',', array_map(fn ($c) => '"'.$c.'"', $columns));
        $sql = 'CREATE '.($unique ? 'UNIQUE ' : '').'INDEX "'.$name.'" ON "public"."'.$table.'" ('.$quoted.')';
        DB::connection($explorer->connectionName())->statement($sql);
        \App\Models\ProjectSchemaChange::create([
            'project_id' => $project->id, 'kind' => 'index_created',
            'detail' => ['table' => $table, 'columns' => $columns, 'unique' => $unique],
            'sql' => $sql, 'owner_user_id' => \Illuminate\Support\Facades\Auth::id(),
        ]);

        return $name;
    }

    /** @return list<array{name:string,table:string,event:string,function:string,enabled:bool}> */
    public static function triggers(Project $project): array
    {
        $conn = ProjectConnectionManager::connection($project);

        return array_map(fn ($r) => [
            'name' => $r->name, 'table' => $r->table, 'event' => $r->event,
            'function' => $r->function, 'enabled' => $r->enabled === 'O',
        ], DB::connection($conn)->select(
            "SELECT t.tgname AS name, c.relname AS \"table\",
                    (CASE WHEN (t.tgtype & 2) > 0 THEN 'BEFORE ' WHEN (t.tgtype & 64) > 0 THEN 'INSTEAD OF ' ELSE 'AFTER ' END
                     || trim(both ' ' FROM (CASE WHEN (t.tgtype & 4) > 0 THEN 'INSERT ' ELSE '' END
                       || CASE WHEN (t.tgtype & 8) > 0 THEN 'DELETE ' ELSE '' END
                       || CASE WHEN (t.tgtype & 16) > 0 THEN 'UPDATE ' ELSE '' END
                       || CASE WHEN (t.tgtype & 32) > 0 THEN 'TRUNCATE ' ELSE '' END))) AS event,
                    p.proname AS function, t.tgenabled AS enabled
               FROM pg_trigger t
               JOIN pg_class c ON c.oid = t.tgrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_proc p ON p.oid = t.tgfoid
              WHERE n.nspname = 'public' AND NOT t.tgisinternal
              ORDER BY c.relname, t.tgname"
        ));
    }

    public static function createTrigger(
        Project $project, string $name, string $table, string $timing,
        string $event, string $function
    ): void {
        $name = DdlService::identifier($name);
        $table = DdlService::identifier($table);
        DdlService::assertNotProtected($table);
        abort_unless(in_array($timing, ['BEFORE', 'AFTER'], true), 422, 'Timing must be BEFORE or AFTER.');
        abort_unless(in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true), 422, 'Event must be INSERT, UPDATE or DELETE.');
        $explorer = ProjectDatabaseExplorer::for($project);
        $explorer->assertTable($table);
        // Function must be a known public trigger function (returns trigger).
        $conn = $explorer->connectionName();
        $fn = DB::connection($conn)->selectOne(
            "SELECT p.proname AS name FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname='public' AND p.proname=? AND pg_get_function_result(p.oid)='trigger' LIMIT 1",
            [DdlService::identifier($function)]
        );
        abort_unless($fn, 422, 'Function must exist in public and return trigger.');
        DB::connection($conn)->statement(
            'CREATE TRIGGER "'.$name.'" '.$timing.' '.$event.' ON "public"."'.$table
            .'" FOR EACH ROW EXECUTE FUNCTION "public"."'.$fn->name.'"()'
        );
        \App\Models\ProjectSchemaChange::create([
            'project_id' => $project->id, 'kind' => 'trigger_created',
            'detail' => ['trigger' => $name, 'table' => $table, 'event' => $timing.' '.$event],
            'sql' => null, 'owner_user_id' => \Illuminate\Support\Facades\Auth::id(),
        ]);
    }

    public static function dropTrigger(Project $project, string $table, string $name): void
    {
        $table = DdlService::identifier($table);
        $name = DdlService::identifier($name);
        DdlService::assertNotProtected($table);
        $explorer = ProjectDatabaseExplorer::for($project);
        $conn = $explorer->connectionName();
        $row = DB::connection($conn)->selectOne(
            "SELECT 1 AS ok FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname='public' AND c.relname=? AND t.tgname=? AND NOT t.tgisinternal",
            [$table, $name]
        );
        abort_unless($row, 404, 'Unknown trigger.');
        DB::connection($conn)->statement('DROP TRIGGER "'.$name.'" ON "public"."'.$table.'"');
    }

    /** @return array{installed:list<string>,allowlist:array<string,array{description:string,installed:bool}>} */
    public static function extensions(Project $project): array
    {
        $conn = ProjectConnectionManager::connection($project);
        $installed = array_column(
            DB::connection($conn)->select('SELECT extname AS name FROM pg_extension ORDER BY extname'),
            'name'
        );
        $allowlist = [];
        foreach (self::EXTENSION_ALLOWLIST as $name => $description) {
            $allowlist[$name] = ['description' => $description, 'installed' => in_array($name, $installed, true)];
        }

        return ['installed' => $installed, 'allowlist' => $allowlist];
    }

    /** @return array{ok:bool,message:string} (never throws for permission outcomes) */
    public static function installExtension(Project $project, string $name): array
    {
        abort_unless(isset(self::EXTENSION_ALLOWLIST[$name]), 403, 'Extension not allowlisted.');
        $conn = ProjectConnectionManager::connection($project);
        try {
            DB::connection($conn)->statement('CREATE EXTENSION IF NOT EXISTS "'.$name.'"');

            return ['ok' => true, 'message' => $name.' installed.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Install refused by the database (needs superuser/DBA): '.SqlRunner::safeError($e->getMessage())];
        }
    }

    /** @return array{roles:list<array{name:string,login:bool}>,policies:list<array{table:string,name:string,command:string}>,note:string} */
    public static function authorization(Project $project): array
    {
        $conn = ProjectConnectionManager::connection($project);
        $roles = array_map(fn ($r) => ['name' => $r->name, 'login' => (bool) $r->login],
            DB::connection($conn)->select(
                "SELECT r.rolname AS name, r.rolcanlogin AS login FROM pg_roles r
                  WHERE r.rolname NOT LIKE 'pg_%'
                  ORDER BY r.rolname LIMIT 50"
            ));
        $policies = array_map(fn ($r) => ['table' => $r->table, 'name' => $r->name, 'command' => $r->command],
            DB::connection($conn)->select(
                "SELECT tablename AS \"table\", policyname AS name, cmd AS command
                   FROM pg_policies WHERE schemaname='public' ORDER BY tablename, policyname"
            ));

        return [
            'roles' => $roles,
            'policies' => $policies,
            'note' => 'Application access is enforced in the Laravel layer (project auth + control-plane gates), not by PostgreSQL RLS. '
                .($policies === [] ? 'No RLS policies exist on public tables.' : count($policies).' RLS policies exist and are listed above.'),
        ];
    }
}
