<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Models\User;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — record a human approval for a task's current changeset. The
 * approval binds to the changeset fingerprint and base revision; apply
 * revalidates both.
 */
class AgentApproveCommand extends Command
{
    protected $signature = 'agent:approve
        {code : Task code (AGT-XXXX) or UUID}
        {--user= : Approver email (required)}
        {--note= : Optional approval note}';

    protected $description = 'Approve a Developer Agent changeset';

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
            $this->error('A valid --user email is required (the approval is recorded with that user).');

            return self::FAILURE;
        }

        try {
            $service->approve($task, $user, $this->option('note'));
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Changeset approved for {$task->code} (bound to fingerprint + base revision).");
        $this->line('Apply with: php artisan agent:apply '.$task->code.' --user='.$userEmail);

        return self::SUCCESS;
    }
}
