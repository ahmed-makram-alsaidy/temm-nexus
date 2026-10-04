<?php

namespace App\Console\Commands;

use App\Models\AgentRuntime;
use App\Services\Agent\AgentRuntimeManager;
use Illuminate\Console\Command;

/**
 * Phase 43 — agent runtime status (connection health for every runtime).
 */
class AgentStatusCommand extends Command
{
    protected $signature = 'agent:status';

    protected $description = 'Show connection status for all configured agent runtimes';

    public function handle(AgentRuntimeManager $manager): int
    {
        $runtimes = AgentRuntime::query()->orderByDesc('created_at')->get();

        if ($runtimes->isEmpty()) {
            $this->info('No agent runtimes configured. Configure one under Settings → Developer Agents.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($runtimes as $runtime) {
            $connection = $manager->forRuntime($runtime)->testConnection($runtime);

            $rows[] = [
                $runtime->display_name,
                $runtime->driver,
                $runtime->mode,
                $runtime->enabled ? 'enabled' : 'disabled',
                $connection->ok ? 'CONNECTED' : 'ERROR',
                $connection->version ?? ($connection->errorCategory ?? '—'),
            ];
        }

        $this->table(['Runtime', 'Driver', 'Mode', 'State', 'Status', 'Version/Category'], $rows);

        return self::SUCCESS;
    }
}
