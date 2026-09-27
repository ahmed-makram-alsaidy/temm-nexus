<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Builds short-lived, project-scoped DB connections from server-side env credentials.
 * Credentials NEVER leave the server: pages/services only ever see the connection name.
 *
 * Phase 21B: the host/port resolve per project (project db_host/db_port
 * columns → PROJECT_DB_HOST/PORT env), so a project can be re-pointed at a
 * separate PostgreSQL endpoint without code changes. The descriptive
 * project↔node mapping (ProjectServiceNode) is kept in sync by the tooling
 * that sets the override; see InfrastructureMapper::dbEndpoint().
 */
class ProjectConnectionManager
{
    public static function envPrefix(Project $project): string
    {
        return 'PROJECT_'.strtoupper(str_replace('-', '_', $project->slug)).'_DB_';
    }

    public static function connectionName(Project $project): string
    {
        return 'project_'.$project->id;
    }

    /**
     * @throws \RuntimeException when credentials are not configured.
     */
    public static function connection(Project $project): string
    {
        $name = self::connectionName($project);
        $host = $project->db_host ?: env('PROJECT_DB_HOST', 'postgres');
        $port = $project->db_port ?: env('PROJECT_DB_PORT', '5432');

        // Re-resolve when the project's endpoint override changed mid-process
        // (21B movability: re-pointing must not require a deploy/restart).
        $current = Config::get("database.connections.{$name}");
        if (! is_array($current)
            || ($current['host'] ?? null) !== $host
            || (string) ($current['port'] ?? '') !== (string) $port) {
            DB::purge($name);

            $p = self::envPrefix($project);
            $database = env($p.'DATABASE');
            $username = env($p.'USERNAME');
            $password = env($p.'PASSWORD');

            if (! $database || ! $username || $password === null || $password === false || $password === '') {
                throw new \RuntimeException("Database credentials for project [{$project->slug}] are not configured.");
            }

            Config::set("database.connections.{$name}", [
                'driver' => 'pgsql',
                'host' => $project->db_host ?: env('PROJECT_DB_HOST', 'postgres'),
                'port' => $project->db_port ?: env('PROJECT_DB_PORT', '5432'),
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }

        return $name;
    }

    public static function ping(Project $project): bool
    {
        try {
            DB::connection(self::connection($project))->select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forget(Project $project): void
    {
        DB::purge(self::connectionName($project));
    }
}
