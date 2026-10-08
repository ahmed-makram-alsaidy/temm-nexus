<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Builds project/environment DB connections from encrypted vault bindings.
 * Legacy server env credentials remain supported for the default environment.
 * Credentials stay in server memory; UI receives only safe connection metadata.
 *
 * Phase 21B: the host/port resolve per project (project db_host/db_port
 * columns → PROJECT_DB_HOST/PORT env), so a project can be re-pointed at a
 * separate PostgreSQL endpoint without code changes. The descriptive
 * project↔node mapping (ProjectServiceNode) is kept in sync by the tooling
 * that sets the override; see InfrastructureMapper::dbEndpoint().
 */
class ProjectConnectionManager
{
    /** Resolve existing defaults without issuing three firstOrCreate reads on every ping. */
    private static function environment(Project $project): ProjectEnvironment
    {
        if (Session::get(EnvironmentContext::sessionKey($project))) {
            return EnvironmentContext::active($project);
        }

        return $project->environments()->where('is_default', true)->orderBy('id')->first()
            ?? EnvironmentContext::active($project);
    }

    public static function envPrefix(Project $project): string
    {
        return 'PROJECT_'.strtoupper(str_replace('-', '_', $project->slug)).'_DB_';
    }

    public static function connectionName(Project $project, ?ProjectEnvironment $environment = null): string
    {
        $environment ??= self::environment($project);

        return 'project_'.$project->id.($environment->is_default ? '' : '_env_'.$environment->id);
    }

    /** Canonical server-side coordinates shared by Data and migration targets. */
    public static function configuration(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $environment ??= self::environment($project);
        abort_unless($environment->project_id === $project->id, 404);
        $coordinates = $environment->database_connection ?? [];
        if ($coordinates !== []) {
            $secret = ProjectSecret::where('project_id', $project->id)
                ->where('name', $environment->database_secret_ref)->first();
            if (! $secret || ($secret->environment_id !== $environment->id
                && ! ($environment->is_default && $secret->environment_id === null))
                || $secret->status !== 'active') {
                throw new \RuntimeException("Database credentials for project [{$project->slug}] are not configured.");
            }
            $password = $secret->value;
        } else {
            // Legacy operator-provisioned credentials belong only to the default environment.
            $prefix = self::envPrefix($project);
            $coordinates = $environment->is_default ? [
                'host' => $project->db_host ?: env('PROJECT_DB_HOST', 'postgres'),
                'port' => $project->db_port ?: env('PROJECT_DB_PORT', '5432'),
                'database' => env($prefix.'DATABASE') ?: $project->db_name,
                'username' => env($prefix.'USERNAME'),
            ] : [];
            $password = $environment->is_default ? env($prefix.'PASSWORD') : null;
        }
        foreach (['host', 'port', 'database', 'username'] as $field) {
            if (! filled($coordinates[$field] ?? null)) {
                throw new \RuntimeException("Database credentials for project [{$project->slug}] are not configured.");
            }
        }
        if (! is_string($password) || $password === '') {
            throw new \RuntimeException("Database credentials for project [{$project->slug}] are not configured.");
        }

        return [
            'driver' => 'pgsql', 'host' => $coordinates['host'], 'port' => $coordinates['port'],
            'database' => $coordinates['database'], 'username' => $coordinates['username'], 'password' => $password,
            'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true,
            'search_path' => 'public', 'sslmode' => $coordinates['sslmode'] ?? 'prefer',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ];
    }

    public static function migrationTarget(Project $project, bool $withCredentials = true): array
    {
        $environment = self::environment($project);
        if (! $withCredentials) {
            $coordinates = $environment->database_connection ?? [];

            return array_filter([
                'host' => $coordinates['host'] ?? ($project->db_host ?: env('PROJECT_DB_HOST', 'postgres')),
                'port' => $coordinates['port'] ?? ($project->db_port ?: env('PROJECT_DB_PORT', '5432')),
                'database' => $coordinates['database'] ?? ($environment->is_default ? (env(self::envPrefix($project).'DATABASE') ?: $project->db_name) : null),
                'sslmode' => $coordinates['sslmode'] ?? 'prefer',
            ]);
        }
        $configuration = self::configuration($project, $environment);
        $reference = $environment->database_secret_ref;
        if (! $reference) {
            // Import legacy server credentials once; never store an inline run password.
            $reference = 'MANAGED_TARGET_PASSWORD';
            SecretVaultService::createSecret($project, $reference, $configuration['password'], [
                'category' => 'database', 'environment_id' => $environment->id,
            ]);
        }

        return array_intersect_key($configuration, array_flip(['host', 'port', 'database', 'username', 'sslmode']))
            + ['secret_refs' => ['password' => $reference]];
    }

    /**
     * @throws \RuntimeException when credentials are not configured.
     */
    public static function connection(Project $project): string
    {
        $environment = self::environment($project);
        $name = self::connectionName($project, $environment);
        $configuration = self::configuration($project, $environment);
        if (Config::get("database.connections.{$name}") !== $configuration) {
            DB::purge($name);
            Config::set("database.connections.{$name}", $configuration);
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

    public static function forget(Project $project, ?ProjectEnvironment $environment = null): void
    {
        $name = self::connectionName($project, $environment);
        DB::purge($name);
        Config::set("database.connections.{$name}", null);
    }
}
