<?php

namespace App\Console\Commands;

use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;
use App\Models\Project;
use App\Services\ControlPlane\InfrastructureMapper;
use Illuminate\Console\Command;

/**
 * Phase 21B: seed the local/demo infrastructure model. Everything is
 * local-only: node-local-01 carries every role (current single-node
 * reality); node-local-02/03 are simulated agents for heartbeat/topology
 * proofs. No provisioning, no SSH, no production contact.
 */
class InfraSeedLocalCommand extends Command
{
    protected $signature = 'infra:seed-local';

    protected $description = 'Seed local/demo infrastructure nodes, services and project mappings (local only).';

    public function handle(): int
    {
        $local = InfrastructureNode::updateOrCreate(['name' => 'node-local-01'], [
            'hostname' => gethostname() ?: 'local',
            'environment' => 'local',
            'roles' => ['proxy', 'application', 'database', 'redis', 'queue_worker', 'realtime', 'monitoring', 'backup'],
            'status' => 'healthy',
            'provider' => 'local',
            'region' => 'local',
            'enabled' => true,
            'last_seen_at' => now(),
        ]);

        $services = [
            'postgres' => 'PostgreSQL 17',
            'redis' => 'Redis 8',
            'caddy' => 'Caddy reverse proxy',
            'laravel-api' => 'Laravel API',
            'horizon' => 'Horizon workers',
            'reverb' => 'Reverb realtime',
            'backup-worker' => 'Backup worker',
        ];
        foreach ($services as $key => $label) {
            InfrastructureService::updateOrCreate(
                ['node_id' => $local->id, 'key' => $key, 'project_id' => null],
                ['label' => $label, 'scope' => 'global', 'status' => 'healthy', 'health' => 'healthy', 'last_check_at' => now()]
            );
        }

        foreach ([
            ['node-local-02', ['queue_worker', 'realtime']],
            ['node-local-03', ['monitoring', 'backup']],
        ] as [$name, $roles]) {
            InfrastructureNode::firstOrCreate(['name' => $name], [
                'hostname' => 'simulated',
                'environment' => 'local',
                'roles' => $roles,
                'status' => 'unknown',
                'provider' => 'local-simulated',
                'region' => 'local',
                'enabled' => true,
            ]);
        }

        foreach (Project::query()->get() as $project) {
            InfrastructureMapper::ensureDefaults($project);
        }

        $this->info('Seeded node-local-01 (+'.count($services).' services), node-local-02/03, and project mappings.');

        return self::SUCCESS;
    }
}
