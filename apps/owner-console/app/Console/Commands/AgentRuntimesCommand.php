<?php

namespace App\Console\Commands;

use App\Models\AgentRuntime;
use Illuminate\Console\Command;

/**
 * Phase 43 — list configured agent runtimes and their configuration shape.
 */
class AgentRuntimesCommand extends Command
{
    protected $signature = 'agent:runtimes';

    protected $description = 'List configured agent runtimes';

    public function handle(): int
    {
        $runtimes = AgentRuntime::query()->orderByDesc('created_at')->get();

        if ($runtimes->isEmpty()) {
            $this->info('No agent runtimes configured.');

            return self::SUCCESS;
        }

        foreach ($runtimes as $runtime) {
            $this->line('<fg=cyan>'.$runtime->display_name.'</> ('.$runtime->driver.', '.$runtime->mode.')');
            $this->line('  status:   '.$runtime->status.($runtime->enabled ? ' (enabled)' : ' (disabled)'));
            $this->line('  version:  '.($runtime->version ?? '—'));
            $this->line('  endpoint: '.($runtime->mode === 'external' ? $runtime->endpoint : config('agent.managed_opencode.endpoint')));
            $this->line('  model:    '.($runtime->default_model ?? 'runtime default'));
            $this->line('  limits:   timeout='.$runtime->timeout_seconds.'s concurrency='.$runtime->max_concurrent_tasks.' retention='.$runtime->workspace_retention_days.'d');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
