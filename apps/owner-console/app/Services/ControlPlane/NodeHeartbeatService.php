<?php

namespace App\Services\ControlPlane;

use App\Models\InfrastructureEvent;
use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;

/**
 * Phase 21B: node heartbeat ingest + stale detection.
 *
 * Agents phone home (outbound HTTPS POST); the Control Plane never opens
 * SSH or executes anything on the node. Liveness is derived from
 * last_seen_at — a node that stops reporting degrades, then goes offline.
 */
class NodeHeartbeatService
{
    /** @return array{node:string,status:string,services:int} */
    public static function ingest(InfrastructureNode $node, array $payload): array
    {
        $cpu = self::pct($payload['cpu'] ?? null);
        $ram = self::pct($payload['ram'] ?? null);
        $disk = self::pct($payload['disk'] ?? null);

        $node->forceFill([
            'cpu_pct' => $cpu,
            'ram_pct' => $ram,
            'disk_pct' => $disk,
            'agent_version' => isset($payload['agent_version'])
                ? mb_substr((string) $payload['agent_version'], 0, 30) : $node->agent_version,
            'status' => 'healthy',
            'last_seen_at' => now(),
        ])->save();

        $count = 0;
        foreach (array_slice($payload['services'] ?? [], 0, 50) as $svc) {
            if (! is_array($svc) || ! isset($svc['key'])) {
                continue;
            }
            $key = mb_substr((string) $svc['key'], 0, 40);
            if (! in_array($key, InfrastructureService::KEYS, true)) {
                continue;
            }
            $status = in_array($svc['status'] ?? 'unknown', ['healthy', 'degraded', 'offline', 'unknown'], true)
                ? $svc['status'] : 'unknown';
            InfrastructureService::updateOrCreate(
                ['node_id' => $node->id, 'key' => $key, 'project_id' => null],
                [
                    'label' => ucfirst(str_replace('-', ' ', $key)),
                    'scope' => 'global',
                    'status' => $status,
                    'health' => $status,
                    'version' => isset($svc['version']) ? mb_substr((string) $svc['version'], 0, 60) : null,
                    'endpoint' => isset($svc['endpoint']) ? mb_substr((string) $svc['endpoint'], 0, 255) : null,
                    'last_check_at' => now(),
                ]
            );
            $count++;
        }

        return ['node' => $node->name, 'status' => 'healthy', 'services' => $count];
    }

    /** Persist computed statuses for stale nodes; returns transition counts. */
    public static function markStale(): array
    {
        $transitions = ['degraded' => 0, 'offline' => 0];
        foreach (InfrastructureNode::query()->get() as $node) {
            $computed = $node->computedStatus();
            if (in_array($computed, ['degraded', 'offline'], true) && $node->status !== $computed) {
                $node->forceFill(['status' => $computed])->save();
                $transitions[$computed]++;
                try {
                    InfrastructureEvent::create([
                        'severity' => $computed === 'offline' ? 'critical' : 'warning',
                        'source' => 'node-heartbeat',
                        'message' => "Node {$node->name} is {$computed} (heartbeat stale).",
                        'context' => [
                            'node' => $node->name,
                            'last_seen_at' => $node->last_seen_at?->toIso8601String(),
                        ],
                    ]);
                } catch (\Throwable) {
                }
            }
        }

        return $transitions;
    }

    protected static function pct(mixed $v): ?float
    {
        if ($v === null || ! is_numeric($v)) {
            return null;
        }

        return max(0, min(100, (float) $v));
    }
}
