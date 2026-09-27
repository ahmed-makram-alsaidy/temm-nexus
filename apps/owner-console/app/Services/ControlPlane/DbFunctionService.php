<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20G first-class PostgreSQL function management on ONE project DB.
 * Names/identifiers strictly validated; bodies run through the project user
 * (least privilege — SECURITY DEFINER is flagged, never silently created:
 * new functions default to SECURITY INVOKER).
 */
class DbFunctionService
{
    public static function list(Project $project): array
    {
        $conn = ProjectConnectionManager::connection($project);

        return array_map(fn ($r) => [
            'oid' => (int) $r->oid,
            'name' => $r->name,
            'schema' => $r->schema,
            'args' => $r->args,
            'returns' => $r->returns,
            'language' => $r->language,
            'security' => $r->security,
            'owner' => $r->owner,
            'definition' => $r->definition,
        ], DB::connection($conn)->select(
            "SELECT p.oid, p.proname AS name, n.nspname AS schema,
                    pg_get_function_arguments(p.oid) AS args,
                    pg_get_function_result(p.oid) AS returns,
                    l.lanname AS language,
                    CASE WHEN p.prosecdef THEN 'DEFINER' ELSE 'INVOKER' END AS security,
                    r.rolname AS owner,
                    pg_get_functiondef(p.oid) AS definition
               FROM pg_proc p
               JOIN pg_namespace n ON n.oid = p.pronamespace
               JOIN pg_language l ON l.oid = p.prolang
               JOIN pg_roles r ON r.oid = p.proowner
              WHERE n.nspname = 'public'
              ORDER BY p.proname"
        ));
    }

    /** @return array{oid:int} */
    public static function save(
        Project $project, string $name, string $args, string $returns,
        string $language, string $security, string $body
    ): array {
        $name = DdlService::identifier($name);
        $language = strtolower(trim($language));
        abort_unless(in_array($language, ['sql', 'plpgsql'], true), 422, 'Language must be SQL or PL/pgSQL.');
        $security = strtoupper(trim($security));
        abort_unless(in_array($security, ['INVOKER', 'DEFINER'], true), 422, 'Security must be INVOKER or DEFINER.');
        abort_if(str_contains($body, '$cp$'), 422, 'Body may not contain the $cp$ delimiter.');
        $args = trim($args);
        if ($args !== '') {
            // Argument list: comma-separated "name type" pairs, conservative charset.
            abort_unless(preg_match('/^[a-zA-Z0-9_,\s\(\)\[\]\."]+$/', $args) === 1, 422, 'Invalid argument list.');
        }
        $returns = trim($returns) === '' ? 'void' : trim($returns);
        abort_unless(preg_match('/^[a-zA-Z0-9_\s\(\)\[\]]+$/', $returns) === 1, 422, 'Invalid return type.');

        $sec = $security === 'DEFINER' ? ' SECURITY DEFINER' : '';
        $sql = 'CREATE OR REPLACE FUNCTION "public"."'.$name.'"('.$args.') RETURNS '.$returns
            .' LANGUAGE '.$language.$sec.' AS $cp$ '.$body.' $cp$';

        $conn = ProjectConnectionManager::connection($project);
        DB::connection($conn)->statement($sql);
        $oid = DB::connection($conn)->selectOne(
            "SELECT p.oid FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname='public' AND p.proname=? ORDER BY p.oid DESC LIMIT 1",
            [$name]
        );

        \App\Models\ProjectSchemaChange::create([
            'project_id' => $project->id, 'kind' => 'function_saved',
            'detail' => ['function' => $name, 'language' => $language, 'security' => $security],
            'sql' => mb_substr($sql, 0, 4000),
            'owner_user_id' => Auth::id(),
        ]);

        return ['oid' => (int) ($oid->oid ?? 0)];
    }

    public static function drop(Project $project, int $oid): string
    {
        $conn = ProjectConnectionManager::connection($project);
        $fn = DB::connection($conn)->selectOne(
            "SELECT p.proname AS name, pg_get_function_identity_arguments(p.oid) AS ident
               FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE p.oid = ? AND n.nspname = 'public'",
            [$oid]
        );
        abort_unless($fn, 404, 'Unknown function.');
        // Identity arguments come from the catalog (not user input).
        DB::connection($conn)->statement('DROP FUNCTION "public"."'.$fn->name.'"('.$fn->ident.')');

        return $fn->name;
    }

    /** Invoke with bound parameters (values never interpolated). */
    public static function invoke(Project $project, int $oid, array $args): array
    {
        $conn = ProjectConnectionManager::connection($project);
        $fn = DB::connection($conn)->selectOne(
            "SELECT p.proname AS name FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE p.oid = ? AND n.nspname = 'public'",
            [$oid]
        );
        abort_unless($fn, 404, 'Unknown function.');
        abort_unless(preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $fn->name) === 1, 422, 'Invalid function name.');
        abort_if(count($args) > 10, 422, 'At most 10 arguments.');

        $placeholders = implode(',', array_fill(0, count($args), '?'));
        $started = microtime(true);
        try {
            $rows = DB::connection($conn)->select(
                'SELECT * FROM "public"."'.$fn->name.'"('.$placeholders.')',
                array_values($args)
            );
        } catch (\Illuminate\Database\QueryException $e) {
            return ['ok' => false, 'error' => SqlRunner::safeError($e->getMessage()), 'rows' => []];
        }

        return [
            'ok' => true, 'error' => null,
            'rows' => array_slice(SqlRunner::rowsToArrays($rows), 0, 100),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ];
    }
}
