<?php

namespace App\Console\Commands;

use App\Models\InfrastructureNode;
use App\Services\ControlPlane\NodeHeartbeatService;
use Illuminate\Console\Command;

/**
 * Phase 21B: LOCAL node-agent simulator. Pushes a heartbeat for a node the
 * same way a real agent would (same ingest path, same validation) so the
 * Control Plane side is proven without any remote host. A real agent is a
 * <50MB outbound-HTTPS loop doing exactly this POST — see docs/NODE_AGENT.md.
 */
class InfraHeartbeatCommand extends Command
{
    protected $signature = 'infra:heartbeat
        {--node=node-local-01 : Node name}
        {--cpu= : CPU percent 0-100 (default: measured host value)}
        {--ram= : RAM percent 0-100}
        {--disk= : Disk percent 0-100}
        {--services= : Comma list key=status, e.g. postgres=healthy,redis=healthy}
        {--agent-version=local-sim-21.0 : Agent version string}';

    protected $description = 'Simulate a local node agent heartbeat (local only).';

    public function handle(): int
    {
        $node = InfrastructureNode::query()->where('name', (string) $this->option('node'))->first();
        if (! $node) {
            $this->error('Unknown node. Run infra:seed-local first.');

            return self::FAILURE;
        }

        $services = [];
        foreach (explode(',', (string) ($this->option('services') ?: '')) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$k, $s] = explode('=', $pair, 2);
            $services[] = ['key' => trim($k), 'status' => trim($s)];
        }
        if ($services === []) {
            foreach ($node->services as $s) {
                $services[] = ['key' => $s->key, 'status' => 'healthy'];
            }
        }

        $result = NodeHeartbeatService::ingest($node, [
            'cpu' => $this->option('cpu') ?? $this->hostCpu(),
            'ram' => $this->option('ram') ?? $this->hostRam(),
            'disk' => $this->option('disk') ?? $this->hostDisk(),
            'agent_version' => (string) $this->option('agent-version'),
            'services' => $services,
        ]);
        $this->info("Heartbeat accepted: {$result['node']} healthy ({$result['services']} services).");

        return self::SUCCESS;
    }

    protected function hostCpu(): ?float
    {
        $load = sys_getloadavg();

        return $load ? round((float) $load[0] * 25, 1) : null; // rough % of 4 vCPU
    }

    protected function hostRam(): ?float
    {
        $info = (string) @file_get_contents('/proc/meminfo');
        if (! preg_match('/MemTotal:\s+(\d+)/', $info, $t) || ! preg_match('/MemAvailable:\s+(\d+)/', $info, $a)) {
            return null;
        }

        return (int) $t[1] > 0 ? round(100 * (1 - ((int) $a[1] / (int) $t[1])), 1) : null;
    }

    protected function hostDisk(): ?float
    {
        $free = @disk_free_space('/var/www/html');
        $total = @disk_total_space('/var/www/html');
        if (! $free || ! $total) {
            return null;
        }

        return round(100 * (1 - $free / $total), 1);
    }
}
