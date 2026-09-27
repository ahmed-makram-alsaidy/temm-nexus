<?php

namespace App\Services\ControlPlane;

use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;
use App\Models\Project;

/**
 * Phase 21B: per-facet health (App / DB / Redis / Worker / Realtime) instead
 * of one generic state. Global view aggregates node heartbeats + service
 * rows; project view resolves each facet through InfrastructureMapper.
 */
class InfrastructureHealthService
{
    /** @return array{nodes:list<array{name:string,status:string,last_seen_at:?string}>,services:array<string,string>,facets:array<string,string>} */
    public static function global(): array
    {
        $nodes = [];
        foreach (InfrastructureNode::query()->orderBy('name')->get() as $n) {
            $nodes[] = [
                'name' => $n->name,
                'status' => $n->computedStatus(),
                'last_seen_at' => $n->last_seen_at?->toIso8601String(),
            ];
        }
        $services = [];
        foreach (InfrastructureService::KEYS as $key) {
            $services[$key] = self::worst(
                InfrastructureService::query()->where('key', $key)->pluck('status')->all()
            );
        }
        $facets = [
            'proxy' => $services['caddy'] ?? 'unknown',
            'application' => $services['laravel-api'] ?? 'unknown',
            'database' => $services['postgres'] ?? 'unknown',
            'redis' => $services['redis'] ?? 'unknown',
            'workers' => $services['horizon'] ?? 'unknown',
            'realtime' => $services['reverb'] ?? 'unknown',
        ];

        return ['nodes' => $nodes, 'services' => $services, 'facets' => $facets];
    }

    /** @return array{facets:array<string,array{status:string,node:?string}>,profile:string} */
    public static function forProject(Project $project): array
    {
        $mapping = InfrastructureMapper::mapping($project);
        $health = ProjectHealthService::for($project)->check();
        $nodeStatus = [];
        foreach (InfrastructureNode::query()->get() as $n) {
            $nodeStatus[$n->name] = $n->computedStatus();
        }
        $serviceFor = [
            'api' => 'app', 'database' => 'db', 'redis' => 'redis',
            'workers' => 'worker', 'realtime' => 'realtime',
        ];
        $live = [
            'api' => $health['application']['checkout_present'] ? 'healthy' : 'unknown',
            'database' => ($health['database']['reachable'] ?? false) ? 'healthy' : 'offline',
            'redis' => ($health['redis']['reachable'] ?? false) ? 'healthy' : 'offline',
            'workers' => ($health['redis']['reachable'] ?? false) ? 'healthy' : 'degraded',
            'realtime' => 'unknown',
        ];
        $facets = [];
        foreach ($serviceFor as $facet => $svc) {
            $node = $mapping[$svc]['node'] ?? null;
            $status = $live[$facet];
            // A stale/offline node caps its facets at degraded/offline.
            if ($node && isset($nodeStatus[$node])) {
                if ($nodeStatus[$node] === 'offline') {
                    $status = 'offline';
                } elseif ($nodeStatus[$node] !== 'healthy' && $status === 'healthy') {
                    $status = 'degraded';
                }
            }
            $facets[$facet] = ['status' => $status, 'node' => $node];
        }

        return ['facets' => $facets, 'profile' => InfrastructureMapper::profile($project)];
    }

    protected static function worst(array $statuses): string
    {
        if ($statuses === []) {
            return 'unknown';
        }
        foreach (['offline', 'degraded', 'unknown', 'healthy'] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }

        return 'unknown';
    }
}
