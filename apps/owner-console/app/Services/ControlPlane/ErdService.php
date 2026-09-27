<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21A: real PostgreSQL schema graph for the interactive ERD.
 *
 * Everything is derived live from pg_catalog for ONE project connection —
 * no manually maintained relationship config exists anywhere. System
 * schemas (pg_*, information_schema) are hidden unless explicitly requested.
 */
class ErdService
{
    /** Schemas never shown unless the caller opts into system schemas. */
    public const SYSTEM_SCHEMAS = ['information_schema'];

    public static function isSystemSchema(string $schema): bool
    {
        return $schema === 'pg_catalog'
            || str_starts_with($schema, 'pg_')
            || in_array($schema, self::SYSTEM_SCHEMAS, true);
    }

    public static function assertSchema(string $schema): string
    {
        abort_unless(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $schema) === 1, 422, 'Invalid schema.');

        return $schema;
    }

    /** @return list<string> user schemas (system hidden unless $includeSystem). */
    public static function schemas(Project $project, bool $includeSystem = false): array
    {
        $conn = ProjectConnectionManager::connection($project);
        $rows = DB::connection($conn)->select(
            'SELECT nspname AS name FROM pg_namespace ORDER BY nspname'
        );
        $out = [];
        foreach ($rows as $r) {
            if (! $includeSystem && self::isSystemSchema($r->name)) {
                continue;
            }
            $out[] = $r->name;
        }

        return $out;
    }

    /**
     * Full schema graph for one schema.
     *
     * @return array{schema:string,generated_at:string,timings:array{metadata_ms:int},
     *   tables:list<array{name:string,rows_estimate:int|null,columns:list<array{name:string,type:string,nullable:bool,default:?string,pk:bool,fk:bool,unique:bool}>,pk:list<string>,indexes:list<array{name:string,definition:string,unique:bool}>>>,
     *   edges:list<array{constraint:string,from_table:string,from_column:string,to_table:string,to_column:string,on_update:string,on_delete:string}>,
     *   enums:list<array{name:string,values:list<string>}>}
     */
    public static function graph(Project $project, string $schema = 'public'): array
    {
        $schema = self::assertSchema($schema);
        $started = microtime(true);
        $conn = ProjectConnectionManager::connection($project);

        // Schema must exist. System catalogs stay hidden unless ?system=1 is
        // passed explicitly (the UI never offers that toggle by default).
        $exists = DB::connection($conn)->selectOne(
            'SELECT 1 AS ok FROM pg_namespace WHERE nspname = ?', [$schema]
        );
        abort_unless($exists !== null, 404, 'Unknown schema.');
        if (self::isSystemSchema($schema)) {
            $allowSystem = false;
            try {
                $allowSystem = request()->boolean('system');
            } catch (\Throwable) {
            }
            abort_unless($allowSystem, 403, 'System schemas are hidden.');
        }

        $tables = array_map(fn ($r) => [
            'name' => $r->name,
            'rows_estimate' => $r->rows === null ? null : (int) $r->rows,
        ], DB::connection($conn)->select(
            "SELECT c.relname AS name, CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END AS rows
               FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ? AND c.relkind IN ('r','p')
              ORDER BY c.relname",
            [$schema]
        ));

        $colRows = DB::connection($conn)->select(
            "SELECT c.relname AS table_name, a.attname AS column_name,
                    pg_catalog.format_type(a.atttypid, a.atttypmod) AS data_type,
                    NOT a.attnotnull AS nullable,
                    pg_get_expr(d.adbin, d.adrelid) AS column_default,
                    COALESCE(pk.cols, '{}') AS pk_cols,
                    COALESCE(fk.cols, '{}') AS fk_cols,
                    COALESCE(uq.cols, '{}') AS uq_cols
               FROM pg_attribute a
               JOIN pg_class c ON c.oid = a.attrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
               LEFT JOIN LATERAL (
                    SELECT array_agg(k.attname) AS cols FROM pg_constraint p
                    JOIN pg_attribute k ON k.attrelid = p.conrelid AND k.attnum = ANY(p.conkey)
                    WHERE p.conrelid = c.oid AND p.contype = 'p' AND a.attnum = ANY(p.conkey)
               ) pk ON true
               LEFT JOIN LATERAL (
                    SELECT array_agg(k.attname) AS cols FROM pg_constraint p
                    JOIN pg_attribute k ON k.attrelid = p.conrelid AND k.attnum = ANY(p.conkey)
                    WHERE p.conrelid = c.oid AND p.contype = 'f' AND a.attnum = ANY(p.conkey)
               ) fk ON true
               LEFT JOIN LATERAL (
                    SELECT array_agg(k.attname) AS cols FROM pg_constraint p
                    JOIN pg_attribute k ON k.attrelid = p.conrelid AND k.attnum = ANY(p.conkey)
                    WHERE p.conrelid = c.oid AND p.contype IN ('u') AND a.attnum = ANY(p.conkey)
                      AND array_length(p.conkey, 1) = 1
               ) uq ON true
              WHERE n.nspname = ? AND c.relkind IN ('r','p')
                AND a.attnum > 0 AND NOT a.attisdropped
              ORDER BY c.relname, a.attnum",
            [$schema]
        );

        $byTable = [];
        foreach ($colRows as $r) {
            $byTable[$r->table_name][] = [
                'name' => $r->column_name,
                'type' => $r->data_type,
                'nullable' => (bool) $r->nullable,
                'default' => $r->column_default,
                'pk' => trim((string) $r->pk_cols, '{}') !== '',
                'fk' => trim((string) $r->fk_cols, '{}') !== '',
                'unique' => trim((string) $r->uq_cols, '{}') !== '',
            ];
        }

        $idxRows = DB::connection($conn)->select(
            'SELECT tablename AS table_name, indexname AS name, indexdef AS definition
               FROM pg_indexes WHERE schemaname = ? ORDER BY tablename, indexname',
            [$schema]
        );
        $idxByTable = [];
        foreach ($idxRows as $r) {
            $idxByTable[$r->table_name][] = [
                'name' => $r->name,
                'definition' => $r->definition,
                'unique' => str_contains(strtoupper($r->definition), 'UNIQUE'),
            ];
        }

        // One edge per FK column pair (composite FKs expand to N edges sharing
        // the constraint name), with referential actions for hover details.
        $edgeRows = DB::connection($conn)->select(
            "SELECT tc.constraint_name,
                    tc.table_name AS from_table, kcu.column_name AS from_column,
                    ccu.table_name AS to_table, ccu.column_name AS to_column,
                    rc.update_rule AS on_update, rc.delete_rule AS on_delete
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
               JOIN information_schema.constraint_column_usage ccu
                 ON ccu.constraint_name = tc.constraint_name
               JOIN information_schema.referential_constraints rc
                 ON rc.constraint_name = tc.constraint_name
                AND rc.constraint_schema = tc.table_schema
              WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = ?
              ORDER BY tc.constraint_name, kcu.ordinal_position",
            [$schema]
        );
        $edges = array_map(fn ($r) => [
            'constraint' => $r->constraint_name,
            'from_table' => $r->from_table,
            'from_column' => $r->from_column,
            'to_table' => $r->to_table,
            'to_column' => $r->to_column,
            'on_update' => $r->on_update,
            'on_delete' => $r->on_delete,
        ], $edgeRows);

        $enumRows = DB::connection($conn)->select(
            "SELECT t.typname AS name, array_agg(e.enumlabel ORDER BY e.enumsortorder) AS values
               FROM pg_type t
               JOIN pg_namespace n ON n.oid = t.typnamespace
               JOIN pg_enum e ON e.enumtypid = t.oid
              WHERE n.nspname = ?
              GROUP BY t.typname ORDER BY t.typname",
            [$schema]
        );
        $enums = array_map(function ($r) {
            $values = is_array($r->values) ? $r->values : explode(',', trim((string) $r->values, '{}'));
            $values = array_map(fn ($v) => trim($v, ' "'), $values);

            return ['name' => $r->name, 'values' => array_values($values)];
        }, $enumRows);

        $tablesOut = [];
        foreach ($tables as $t) {
            $cols = $byTable[$t['name']] ?? [];
            $tablesOut[] = [
                'name' => $t['name'],
                'rows_estimate' => $t['rows_estimate'],
                'columns' => $cols,
                'pk' => array_values(array_column(array_filter($cols, fn ($c) => $c['pk']), 'name')),
                'indexes' => $idxByTable[$t['name']] ?? [],
            ];
        }

        return [
            'schema' => $schema,
            'generated_at' => now()->toIso8601String(),
            'timings' => ['metadata_ms' => (int) ((microtime(true) - $started) * 1000)],
            'tables' => $tablesOut,
            'edges' => $edges,
            'enums' => $enums,
        ];
    }
}
