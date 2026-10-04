<?php

namespace App\Jobs\Agent;

use App\Models\AgentTask;
use App\Services\Agent\AgentTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs one Developer Agent task to completion (session start → prompt →
 * event stream → changeset → awaiting approval). Queued on the dedicated
 * `agents` queue so long-running coding sessions never block platform work.
 *
 * The timeout is generous (coding tasks are slow) but bounded; the task
 * service enforces its own inner deadline and records TIMEOUT failures.
 */
class RunAgentTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public function __construct(public string $taskId)
    {
    }

    public function handle(AgentTaskService $service): void
    {
        $task = AgentTask::find($this->taskId);

        if ($task === null) {
            return;
        }

        $service->run($task);
    }

    public function failed(Throwable $exception): void
    {
        $task = AgentTask::find($this->taskId);

        if ($task !== null && ! $task->isTerminal()) {
            $task->update([
                'status' => AgentTask::STATUS_FAILED,
                'error_category' => 'SESSION_FAILED',
                'error_message' => 'Task worker died before completion.',
                'finished_at' => now(),
            ]);
        }
    }
}
