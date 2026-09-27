<?php

namespace App\Services\ControlPlane;

use App\Models\ProjectFunction;
use App\Models\ProjectTask;
use App\Models\TaskRun;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 20O cron execution engine. Targets are deliberately narrow:
 *   artisan   — small allowlist of non-blocking project commands
 *   function  — a project Server Function (internal actor)
 * NO arbitrary shell commands, ever.
 */
class TaskRunner
{
    public const ARTISAN_ALLOWLIST = [
        'cache:clear', 'schedule:run', 'pulse:check',
        'demo:throw-test-exception', 'demo:emit-test-event', 'demo:dispatch-test-job',
    ];

    /** @return array{status:string,output:string,duration_ms:int} */
    public static function run(ProjectTask $task, string $actor = 'owner'): array
    {
        $project = $task->project;
        $started = microtime(true);
        $requestId = SqlRunner::requestId();
        $status = 'ok';
        $output = '';
        $error = null;

        try {
            if ($task->target_type === 'artisan') {
                abort_unless(in_array($task->target_ref, self::ARTISAN_ALLOWLIST, true), 403, 'Command not permitted as a cron target.');
                $result = ProjectArtisan::run($project, $task->target_ref);
                abort_unless($result['ok'], 422, 'Command failed: '.mb_substr($result['output'], 0, 300));
                $output = mb_substr($result['output'], 0, 2000);
            } elseif ($task->target_type === 'function') {
                $fn = ProjectFunction::query()
                    ->where('project_id', $project->id)->where('slug', $task->target_ref)->first();
                abort_unless($fn, 404, 'Function not found.');
                $result = FunctionRunner::invoke($fn, [
                    'method' => 'POST', 'query' => [], 'headers' => [],
                    'body' => ['trigger' => 'cron', 'task' => $task->name],
                ], 'cron:'.$task->name, $requestId);
                abort_unless($result['status'] < 400, 422, 'Function returned HTTP '.$result['status']);
                $output = mb_substr(json_encode($result['body']) ?: '', 0, 2000);
            } else {
                abort(422, 'Unknown target type.');
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $error = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException
                ? $e->getMessage()
                : 'Task failed.';
            $output = mb_substr($error ?? '', 0, 500);
        }

        $duration = (int) ((microtime(true) - $started) * 1000);
        TaskRun::create([
            'task_id' => $task->id, 'request_id' => $requestId,
            'status' => $status, 'duration_ms' => $duration,
            'output' => LogSanitizer::sanitize($output),
            'error' => $error ? LogSanitizer::sanitize($error) : null,
        ]);
        $next = CronService::nextRuns($task->cron, 1);
        $task->forceFill([
            'last_run_at' => now(), 'last_status' => $status,
            'last_duration_ms' => $duration,
            'next_run_at' => $next[0] ?? null,
        ])->save();

        AdminAudit::record('TASK_RUN', $project, 'task', $task->id, [
            'task' => $task->name, 'status' => $status, 'actor' => $actor,
            'request_id' => $requestId,
        ]);

        return ['status' => $status, 'output' => $output, 'duration_ms' => $duration];
    }

    /** Invoked every minute by the console scheduler. Returns runs executed. */
    public static function runDue(): int
    {
        $count = 0;
        foreach (
            ProjectTask::query()->where('enabled', true)
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', now())
                ->get() as $task
        ) {
            try {
                self::run($task, 'scheduler');
                $count++;
            } catch (\Throwable) {
                // One bad task never stops the sweep; the error is in task_runs.
            }
        }

        return $count;
    }

    public static function scheduleNext(ProjectTask $task): void
    {
        $next = CronService::nextRuns($task->cron, 1);
        $task->forceFill(['next_run_at' => $next[0] ?? null])->save();
    }
}
