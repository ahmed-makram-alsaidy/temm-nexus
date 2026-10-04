<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Models\User;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — apply an approved changeset to the authoritative source and run
 * the verification policy. Approval is revalidated (fingerprint + revision)
 * inside the service; CLI and web share the exact same code path.
 */
class AgentApplyCommand extends Command
{
    protected $signature = 'agent:apply
        {code : Task code (AGT-XXXX) or UUID}
        {--user= : Acting user email (required)}';

    protected $description = 'Apply an approved Developer Agent changeset, then verify';

    public function handle(AgentTaskService $service): int
    {
        $task = AgentTask::where('code', $this->argument('code'))->orWhere('id', $this->argument('code'))->first();

        if ($task === null) {
            $this->error('Task not found.');

            return self::FAILURE;
        }

        $userEmail = (string) $this->option('user');
        $user = $userEmail !== '' ? User::where('email', $userEmail)->first() : null;

        if ($user === null) {
            $this->error('A valid --user email is required (the apply runs with that user\'s authorization).');

            return self::FAILURE;
        }

        try {
            $service->apply($task, $user);
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $task->refresh();

        $this->info("Applied changeset for {$task->code}.");
        $this->line('Task status: '.$task->status);

        $verification = $task->verifications()->latest('created_at')->first();
        if ($verification !== null) {
            $this->line('Verification: '.$verification->status);
            foreach ($verification->commands ?? [] as $command) {
                $this->line('  $ '.$command['command'].' → exit '.($command['exit_code'] ?? '—'));
            }
        }

        return $task->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
