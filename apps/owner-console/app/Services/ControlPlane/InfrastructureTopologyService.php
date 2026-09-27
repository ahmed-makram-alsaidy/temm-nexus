<?php

namespace App\Services\ControlPlane;

use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;

/**
 * Phase 21B: automatic infrastructure/service topology (NOT the DB ERD).
 * Nodes are placed by role layer; edges follow the serving direction
 * proxy → app → data/queue/realtime. Click a node for its detail page.
 */
class InfrastructureTopologyService
{
    /** @return array{nodes:list<array{id:int,name:string,roles:list<string>,status:string,layer:int}>,edges:list<array{from:string,to:string,via:string}>} */
    public static function topology(): array
    {
        $nodes = InfrastructureNode::query()->with('services')->orderBy('name')->get();
        $has = []; // service key => node names serving it
        foreach ($nodes as $n) {
            foreach ($n->services as $s) {
                $has[$s->key][] = $n->name;
            }
            foreach ($n->roles ?? [] as $role) {
                $has['role:'.$role][] = $n->name;
            }
        }

        $layerOf = function (array $roles): int {
            foreach (['proxy', 'application', 'queue_worker', 'database', 'redis', 'realtime', 'monitoring', 'backup'] as $i => $r) {
                if (in_array($r, $roles, true)) {
                    return $i;
                }
            }

            return 7;
        };

        $out = [];
        foreach ($nodes as $n) {
            $out[] = [
                'id' => $n->id,
                'name' => $n->name,
                'roles' => $n->roles ?? [],
                'status' => $n->computedStatus(),
                'layer' => $layerOf($n->roles ?? []),
            ];
        }

        // Logical serving edges, emitted only between nodes that exist.
        $rules = [
            ['proxy', 'application', 'http'],
            ['application', 'database', 'sql'],
            ['application', 'redis', 'cache/queue'],
            ['queue_worker', 'redis', 'jobs'],
            ['queue_worker', 'database', 'sql'],
            ['application', 'realtime', 'ws'],
            ['monitoring', 'application', 'scrape'],
            ['backup', 'database', 'pg_dump'],
        ];
        $byRole = [];
        foreach ($nodes as $n) {
            foreach ($n->roles ?? [] as $role) {
                $byRole[$role][] = $n->name;
            }
        }
        $edges = [];
        foreach ($rules as [$from, $to, $via]) {
            foreach ($byRole[$from] ?? [] as $a) {
                foreach ($byRole[$to] ?? [] as $b) {
                    if ($a === $b) {
                        continue;
                    }
                    $edges[] = ['from' => $a, 'to' => $b, 'via' => $via];
                }
            }
        }

        return ['nodes' => $out, 'edges' => $edges];
    }
}
