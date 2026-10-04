<?php

namespace App\Console\Commands;

use App\Models\AgentRuntime;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Console\Command;

/**
 * Phase 43 — create and run an agent task from the CLI.
 *
 * CLI and web call the same AgentTaskService: identical authorization
 * (--user must hold agents.run for the project), identical workspace
 * isolation, identical audit trail. The task executes on the agents queue;
 * poll progress with agent:show.
 */
class AgentRunCommand extends Command
{
    protected $signature = 'agent:run
        {--project= : Project slug (required)}
        {--runtime= : Runtime display name (defaults to the only enabled runtime)}
        {--model= : Canonical provider/model (defaults to the runtime default)}
        {--user= : Acting user email (authorization holder; required)}
        {--wait : Follow the task until it reaches a terminal state}
        {prompt? : The task instruction}';

    protected $description = 'Create and run a Developer Agent task';

    public function handle(AgentTaskService $service): int
    {
        $slug = (string) $this->option('project');
        $prompt = trim((string) $this->argument('prompt'));

        if ($slug === '' || $prompt === '') {
            $this->error('Usage: php artisan agent:run --project=<slug> --user=<email> [--runtime=<name>] [--model=provider/model] "task text"');

            return self::FAILURE;
        }

        $project = Project::where('slug', $slug)->first();
        if ($project === null) {
            $this->error("Project [{$slug}] not found.");

            return self::FAILURE;
        }

        $user = User::where('email', (string) $this->option('user'))->first();
        if ($user === null) {
            $this->error('A valid --user email is required (the task runs with that user\'s authorization).');

            return self::FAILURE;
        }

        $runtimeQuery = AgentRuntime::where('enabled', true);
        $runtime = ((string) $this->option('runtime')) !== ''
            ? $runtimeQuery->where('display_name', $this->option('runtime'))->first()
            : $runtimeQuery->orderByDesc('created_at')->first();

        if ($runtime === null) {
            $this->error('No matching enabled runtime.');

            return self::FAILURE;
        }

        try {
            $task = $service->createTask($user, $project, $runtime, $this->option('model'), $prompt);
        } catch (AgentRuntimeException $e) {
            $this->error($e->category.': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Task created: {$task->code}");
        $this->line('Status: '.$task->status.' — follow with: php artisan agent:show '.$task->code);

        if ($this->option('wait')) {
            $terminal = ['completed', 'failed', 'cancelled', 'stale'];
            while (true) {
                sleep(3);
                $task->refresh();
                $this->line('  ['.$task->status.']');
                if (in_array($task->status, $terminal, true)) {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }
}
