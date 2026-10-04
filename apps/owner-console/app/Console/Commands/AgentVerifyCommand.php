<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — (re-)run the project verification policy for an applied task.
 * Verification never deploys.
 */
class AgentVerifyCommand extends Command
{
    protected $signature = 'agent:verify
        {code : Task code (AGT-XXXX) or UUID}';

    protected $description = 'Run the verification policy for an applied Developer Agent task';

    public function handle(AgentTaskService $service): int
    {
        $task = AgentTask::findByCodeOrId((string) $this->argument('code'));

        if ($task === null) {
            $this->error('Task not found.');

            return self::FAILURE;
        }

        if ($task->applied_at === null) {
            $this->error('Task has no applied changeset; verification only runs after apply.');

            return self::FAILURE;
        }

        try {
            $verification = $service->verify($task);
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Verification: '.$verification->status);
        foreach ($verification->commands ?? [] as $command) {
            $this->line('  $ '.$command['command'].' → exit '.($command['exit_code'] ?? '—').' ('.($command['duration_ms'] ?? '—').'ms)');
        }

        return $verification->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
