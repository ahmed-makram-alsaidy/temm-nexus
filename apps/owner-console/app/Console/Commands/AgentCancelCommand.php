<?php

namespace App\Console\Commands;

use App\Models\AgentTask;
use App\Models\User;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — cancel a running or pending task. Authorization is enforced by
 * the service (agents.cancel at project scope for the acting user).
 */
class AgentCancelCommand extends Command
{
    protected $signature = 'agent:cancel
        {code : Task code (AGT-XXXX) or UUID}
        {--user= : Acting user email (required)}';

    protected $description = 'Cancel a Developer Agent task';

    public function handle(AgentTaskService $service): int
    {
        [$task, $user] = $this->resolve($this->argument('code'), $this->option('user'));

        if ($task === null || $user === null) {
            return self::FAILURE;
        }

        try {
            $service->cancel($task, $user);
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Task {$task->code} cancelled.");

        return self::SUCCESS;
    }

    /** @return array{0: AgentTask|null, 1: User|null} */
    protected function resolve(string $code, mixed $userEmail): array
    {
        $task = AgentTask::where('code', $code)->orWhere('id', $code)->first();
        if ($task === null) {
            $this->error('Task not found.');

            return [null, null];
        }

        if (! is_string($userEmail) || $userEmail === '') {
            $this->error('A valid --user email is required (the action runs with that user\'s authorization).');

            return [$task, null];
        }

        $user = User::where('email', $userEmail)->first();
        if ($user === null) {
            $this->error('User not found.');

            return [$task, null];
        }

        return [$task, $user];
    }
}
