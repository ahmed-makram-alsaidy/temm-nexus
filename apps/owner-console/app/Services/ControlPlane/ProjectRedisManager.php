<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 21B: per-project Redis connections honoring the same override chain
 * as the database (mapping endpoint_override → project columns → env).
 * The default shared connection is untouched; this builds short-lived
 * `project_redis_{id}` connections for movability proofs and future splits.
 */
class ProjectRedisManager
{
    public static function connectionName(Project $project): string
    {
        $suffix = $project->id ?? preg_replace('/[^a-z0-9_]/i', '_', (string) $project->slug) ?: 'x';

        return 'project_redis_'.$suffix;
    }

    /** @return array{host:string,port:int} */
    public static function endpoint(Project $project): array
    {
        return InfrastructureMapper::redisEndpoint($project);
    }

    public static function connection(Project $project): \Illuminate\Redis\Connections\Connection
    {
        $name = self::connectionName($project);
        $ep = self::endpoint($project);

        $current = Config::get("database.redis.{$name}");
        if (! is_array($current)
            || ($current['host'] ?? null) !== $ep['host']
            || (int) ($current['port'] ?? 0) !== $ep['port']) {
            Config::set("database.redis.{$name}", [
                'host' => $ep['host'],
                'port' => $ep['port'],
                'username' => env('REDIS_USERNAME'),
                'password' => env('REDIS_PASSWORD'),
                'database' => env('REDIS_DB', '0'),
                'max_retries' => 3,
            ]);
            // The RedisManager snapshots database.redis on first resolution;
            // drop the singleton so the new connection is actually picked up.
            app()->forgetInstance('redis');
        }

        return Redis::connection($name);
    }

    public static function ping(Project $project): bool
    {
        try {
            self::connection($project)->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
