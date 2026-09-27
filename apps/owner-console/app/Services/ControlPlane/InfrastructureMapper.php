<?php

namespace App\Services\ControlPlane;

use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;
use App\Models\Project;
use App\Models\ProjectServiceNode;

/**
 * Phase 21B: project ↔ infrastructure mapping.
 *
 * Single-node default: every service maps to node-local-01. Split /
 * distributed profiles remap rows (descriptively for now) — endpoint
 * resolution always prefers an explicit per-project host/port override,
 * then the service mapping's endpoint_override, then environment defaults.
 */
class InfrastructureMapper
{
    /** Ensure every project service has a mapping row (single-node default). */
    public static function ensureDefaults(Project $project): void
    {
        $node = InfrastructureNode::query()->where('name', 'node-local-01')->first();
        foreach (ProjectServiceNode::SERVICES as $service) {
            ProjectServiceNode::firstOrCreate(
                ['project_id' => $project->id, 'service' => $service],
                ['node_id' => $node?->id]
            );
        }
    }

    /** @return array<string,array{node:?string,endpoint_override:?string}> */
    public static function mapping(Project $project): array
    {
        self::ensureDefaults($project);
        $out = [];
        foreach (ProjectServiceNode::query()->where('project_id', $project->id)->with('node')->get() as $row) {
            $out[$row->service] = [
                'node' => $row->node?->name,
                'endpoint_override' => $row->endpoint_override,
            ];
        }

        return $out;
    }

    /**
     * Resolve the database host/port a project should use right now.
     * Chain: mapping endpoint_override → project columns → env defaults.
     * (ProjectConnectionManager::connection() reads the project columns;
     * tooling that moves a project must set both — see infra:move-db.)
     */
    public static function dbEndpoint(Project $project): array
    {
        $override = ProjectServiceNode::query()
            ->where('project_id', $project->id)->where('service', 'db')->first()?->endpoint_override;
        if ($override) {
            return self::splitEndpoint($override, 5432);
        }
        if ($project->db_host) {
            return ['host' => $project->db_host, 'port' => (int) ($project->db_port ?: 5432)];
        }

        return [
            'host' => env('PROJECT_DB_HOST', 'postgres'),
            'port' => (int) env('PROJECT_DB_PORT', 5432),
        ];
    }

    /** Resolve the Redis host/port a project should use right now. */
    public static function redisEndpoint(Project $project): array
    {
        $override = ProjectServiceNode::query()
            ->where('project_id', $project->id)->where('service', 'redis')->first()?->endpoint_override;
        if ($override) {
            return self::splitEndpoint($override, 6379);
        }
        if ($project->redis_host) {
            return ['host' => $project->redis_host, 'port' => (int) ($project->redis_port ?: 6379)];
        }

        return [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => (int) env('REDIS_PORT', 6379),
        ];
    }

    /** Resolve the Reverb host/port a project should use right now. */
    public static function reverbEndpoint(Project $project): array
    {
        $override = ProjectServiceNode::query()
            ->where('project_id', $project->id)->where('service', 'realtime')->first()?->endpoint_override;
        if ($override) {
            return self::splitEndpoint($override, 8080);
        }
        if ($project->reverb_host) {
            return ['host' => $project->reverb_host, 'port' => (int) ($project->reverb_port ?: 8080)];
        }

        return [
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => (int) env('REVERB_PORT', 8080),
        ];
    }

    /** @return array{host:string,port:int} */
    protected static function splitEndpoint(string $endpoint, int $defaultPort): array
    {
        if (str_contains($endpoint, ':')) {
            [$host, $port] = explode(':', $endpoint, 2);
            $host = trim($host) !== '' ? trim($host) : 'localhost';

            return ['host' => mb_substr($host, 0, 255), 'port' => (int) $port > 0 ? (int) $port : $defaultPort];
        }

        return ['host' => mb_substr(trim($endpoint), 0, 255), 'port' => $defaultPort];
    }

    /** Current descriptive infrastructure profile for a project. */
    public static function profile(Project $project): string
    {
        return $project->infra_profile ?: config('infrastructure.profile', 'single');
    }
}
