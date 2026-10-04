<?php

namespace App\Services\Agent;

use App\Jobs\Agent\RunAgentTask;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\AgentTaskCommand;
use App\Models\AgentTaskEvent;
use App\Models\AgentWorkspace;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Agent\Contract\AgentRuntimeEvent;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Task lifecycle orchestration for the Developer Agent. Both the web UI and
 * the CLI call THIS service — there is no duplicate business logic.
 *
 * Lifecycle: queued → starting → running → awaiting_approval → (apply) →
 * verifying → completed | failed | cancelled | stale.
 */
class AgentTaskService
{
    public function __construct(
        protected AgentRuntimeManager $runtimes,
        protected AgentWorkspaceService $workspaces,
        protected AgentChangesetService $changesets,
        protected AgentVerificationService $verification,
    ) {
    }

    // ── Creation ────────────────────────────────────────────────────────

    public function createTask(User $user, Project $project, AgentRuntime $runtime, ?string $model, string $prompt, ?string $title = null): AgentTask
    {
        Access::for($user)->authorize(Capability::AGENTS_RUN, 'project', $project->workspace_id, $project->id);

        if (! $runtime->enabled) {
            throw AgentRuntimeException::make(AgentRuntimeException::RUNTIME_UNAVAILABLE, 'Runtime is disabled.');
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw AgentRuntimeException::make(AgentRuntimeException::INVALID_RUNTIME_RESPONSE, 'Task prompt is empty.');
        }
        if (strlen($prompt) > (int) config('agent.limits.max_prompt_bytes', 32768)) {
            throw AgentRuntimeException::make(AgentRuntimeException::INVALID_RUNTIME_RESPONSE, 'Task prompt exceeds the size limit.');
        }

        // Canonical model id shape (provider/model) when provided.
        if ($model !== null && $model !== '' && ! str_contains($model, '/')) {
            throw AgentRuntimeException::make(AgentRuntimeException::MODEL_UNAVAILABLE, 'Model must be the canonical provider/model id.');
        }
        if ($model === null || $model === '') {
            $model = $runtime->default_model;
        }

        // Concurrency limit (documented, bounded).
        $active = AgentTask::whereIn('status', [
            AgentTask::STATUS_QUEUED, AgentTask::STATUS_STARTING, AgentTask::STATUS_RUNNING,
            AgentTask::STATUS_AWAITING_APPROVAL, AgentTask::STATUS_APPLYING, AgentTask::STATUS_VERIFYING,
        ])->where('agent_runtime_id', $runtime->id)->count();

        if ($active >= max(1, (int) $runtime->max_concurrent_tasks)) {
            throw AgentRuntimeException::make(AgentRuntimeException::RUNTIME_UNAVAILABLE, 'Runtime is at its concurrent-task limit.');
        }

        $task = DB::transaction(function () use ($user, $project, $runtime, $model, $prompt, $title) {
            $task = AgentTask::create([
                'code' => $this->nextCode(),
                'created_by' => $user->id,
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'agent_runtime_id' => $runtime->id,
                'model' => $model,
                'prompt' => $prompt,
                'title' => $title !== null ? mb_substr($title, 0, 200) : mb_substr($prompt, 0, 120),
                'status' => AgentTask::STATUS_QUEUED,
            ]);

            // Isolation is established at creation: the worktree is cut from
            // the CURRENT source revision and bound to the task row.
            $workspace = $this->workspaces->createForTask($task, $project);

            $task->update([
                'agent_workspace_id' => $workspace->id,
                'base_revision' => $workspace->base_revision,
            ]);

            return $task;
        });

        AdminAudit::record('AGENT_TASK_CREATED', $project, 'agent_task', $task->id, [
            'code' => $task->code,
            'runtime' => $runtime->driver,
            'model' => $task->model,
            'base_revision' => substr((string) $task->base_revision, 0, 12),
        ]);

        RunAgentTask::dispatch($task->id)->onQueue((string) config('agent.queue', 'agents'));

        return $task;
    }

    // ── Execution (called by the queue job) ─────────────────────────────

    public function run(AgentTask $task): void
    {
        $task->refresh();

        if ($task->status !== AgentTask::STATUS_QUEUED) {
            return; // cancelled / already handled while queued
        }

        $runtime = $task->runtime;
        $workspace = $task->workspaceRecord;

        if ($runtime === null || $workspace === null) {
            $this->fail($task, AgentRuntimeException::WORKSPACE_FAILED, 'Task is missing its runtime or workspace.');

            return;
        }

        $task->update(['status' => AgentTask::STATUS_STARTING, 'started_at' => now(), 'attempts' => $task->attempts + 1]);
        $this->recordEvent($task, new AgentRuntimeEvent('status', 'Task starting'), AgentTask::STATUS_STARTING);

        $driver = $this->runtimes->forRuntime($runtime);

        try {
            $sessionId = $driver->startSession(
                $runtime,
                $workspace->path,
                $task->model,
                AgentExecutionPolicy::sessionPermissionConfig(),
            );

            // Re-read the row: a cancel may have raced the session creation.
            $task->refresh();
            if ($task->status === AgentTask::STATUS_CANCELLED) {
                try {
                    $driver->abort($runtime, $sessionId);
                } catch (AgentRuntimeException) {
                }

                return;
            }

            $task->update(['status' => AgentTask::STATUS_RUNNING, 'runtime_session_id' => $sessionId]);
            $this->recordEvent($task, new AgentRuntimeEvent('status', 'Session started'), AgentTask::STATUS_RUNNING);

            $driver->sendPrompt($runtime, $sessionId, $task->prompt, $task->model);

            $this->consumeEvents($task, $runtime, $driver, $sessionId, $workspace->path);
        } catch (AgentRuntimeException $e) {
            if ($e->category === AgentRuntimeException::TASK_CANCELLED) {
                return;
            }

            $this->fail($task, $e->category, $e->getMessage());
        } catch (\Throwable $e) {
            $this->fail($task, AgentRuntimeException::SESSION_FAILED, 'Unexpected failure while running the task.');
        }
    }

    /**
     * Consume the normalized event stream until the session goes idle, the
     * deadline passes, or the task is cancelled externally.
     */
    protected function consumeEvents(AgentTask $task, AgentRuntime $runtime, $driver, string $sessionId, string $directory): void
    {
        $deadline = now()->addSeconds(max(60, (int) $runtime->timeout_seconds));
        $idleTimeout = (int) config('agent.limits.event_idle_timeout', 120);
        $usage = ['tokens' => null, 'cost' => null];
        $reconnects = 0;

        while (now()->lt($deadline)) {
            $task->refresh();
            if ($task->status === AgentTask::STATUS_CANCELLED) {
                try {
                    $driver->abort($runtime, $sessionId);
                } catch (AgentRuntimeException) {
                }

                return;
            }

            $sawIdle = false;

            try {
                foreach ($driver->events($runtime, $sessionId, $directory, $idleTimeout) as $event) {
                    if ($event->sessionId !== null && $event->sessionId !== $sessionId) {
                        continue; // another session on this directory (should not happen; filter defensively)
                    }

                    if ($event->type === AgentTaskEvent::TYPE_COMPLETED) {
                        $sawIdle = true;
                    }

                    if ($event->type === AgentTaskEvent::TYPE_PERMISSION) {
                        $this->respondToPermission($task, $runtime, $driver, $event);
                    }

                    $this->recordEvent($task, $event);

                    if ($event->type === AgentTaskEvent::TYPE_COMMAND) {
                        $this->recordCommand($task, $event);
                    }

                    if (($event->payload['tokens'] ?? null) !== null || ($event->payload['cost'] ?? null) !== null) {
                        $usage = array_merge($usage, array_filter($event->payload, fn ($k) => in_array($k, ['tokens', 'cost']), ARRAY_FILTER_USE_KEY));
                    }

                    $task->refresh();
                    if ($task->status === AgentTask::STATUS_CANCELLED) {
                        try {
                            $driver->abort($runtime, $sessionId);
                        } catch (AgentRuntimeException) {
                        }

                        return;
                    }

                    if ($sawIdle) {
                        break;
                    }
                }
            } catch (AgentRuntimeException $e) {
                $this->fail($task, $e->category, $e->getMessage());

                return;
            }

            if ($sawIdle) {
                break;
            }

            // Stream ended without an idle event: confirm real session state
            // before reconnecting, so a quiet-but-working session isn't lost.
            if (! $this->sessionBusy($runtime, $driver, $directory, $sessionId)) {
                break;
            }

            if (++$reconnects > (int) config('agent.limits.max_stream_reconnects', 30)) {
                $this->fail($task, AgentRuntimeException::TIMEOUT, 'Task exceeded the streaming watch budget.');

                return;
            }
        }

        $task->refresh();
        if ($task->status === AgentTask::STATUS_CANCELLED) {
            return;
        }

        if (now()->gte($deadline)) {
            $this->fail($task, AgentRuntimeException::TIMEOUT, 'Task exceeded its total time budget.');

            return;
        }

        $task->update(['usage' => array_filter($usage)]);

        // Final deterministic changeset from the isolated workspace.
        $runtimeDiffs = null;
        try {
            $runtimeDiffs = $driver->diff($runtime, $sessionId);
        } catch (AgentRuntimeException) {
            // Runtime diff is advisory; the workspace git diff is authoritative.
        }

        $changeset = $this->changesets->build($task, $runtimeDiffs);

        if ($changeset === null) {
            $task->update(['status' => AgentTask::STATUS_COMPLETED, 'finished_at' => now()]);
            $this->recordEvent($task, new AgentRuntimeEvent('completed', 'Task finished without producing changes'));

            return;
        }

        AdminAudit::record('AGENT_CHANGESET_GENERATED', $task->project, 'agent_task', $task->id, [
            'files' => $changeset->files_added + $changeset->files_modified + $changeset->files_deleted,
            'additions' => $changeset->additions,
            'deletions' => $changeset->deletions,
            'fingerprint' => $changeset->fingerprint,
        ]);

        $task->update(['status' => AgentTask::STATUS_AWAITING_APPROVAL]);
        $this->recordEvent($task, new AgentRuntimeEvent('status', 'Changeset ready — awaiting approval'));
    }

    // ── Approval / apply / verify orchestration (UI + CLI) ──────────────

    public function approve(AgentTask $task, User $approver, ?string $note = null): void
    {
        app(AgentApprovalService::class)->approve($task, $approver, $note);
    }

    public function reject(AgentTask $task, User $rejector, ?string $note = null): void
    {
        app(AgentApprovalService::class)->reject($task, $rejector, $note);
    }

    public function apply(AgentTask $task, User $operator): AgentChangeset
    {
        $changeset = app(AgentApplyService::class)->apply($task, $operator);

        $this->verify($task);

        return $changeset;
    }

    public function verify(AgentTask $task): \App\Models\AgentVerification
    {
        return $this->verification->verify($task);
    }

    public function cancel(AgentTask $task, User $user): void
    {
        Access::for($user)->authorize(Capability::AGENTS_CANCEL, 'project', $task->workspace_id, $task->project_id);

        if ($task->isTerminal()) {
            throw AgentRuntimeException::make(AgentRuntimeException::TASK_CANCELLED, 'Task already finished.');
        }

        if ($task->status === AgentTask::STATUS_AWAITING_APPROVAL) {
            $task->update(['status' => AgentTask::STATUS_CANCELLED, 'finished_at' => now()]);
        } else {
            // In-flight tasks flip to cancelled; the runner sees it and aborts.
            $task->update(['status' => AgentTask::STATUS_CANCELLED]);
        }

        AdminAudit::record('AGENT_TASK_CANCELLED', $task->project, 'agent_task', $task->id, ['code' => $task->code]);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** TEMM policy answers runtime permission prompts; nothing prompts a human mid-task. */
    protected function respondToPermission(AgentTask $task, AgentRuntime $runtime, $driver, AgentRuntimeEvent $event): void
    {
        $requestId = $event->payload['request_id'] ?? null;
        $permission = $event->payload['permission'] ?? null;

        if (! is_string($requestId) || $requestId === '') {
            return;
        }

        $reply = match ($permission) {
            'read', 'edit', 'glob', 'grep', 'list', 'bash', 'task' => 'once',
            'external_directory', 'question' => 'reject',
            default => 'reject', // unknown permission classes default to refusal
        };

        try {
            $client = method_exists($driver, 'client') ? $driver->client($runtime) : null;
            $client?->replyPermission($requestId, $reply);
        } catch (AgentRuntimeException) {
            // A permission reply race (already answered) is non-fatal.
        }

        $this->recordEvent($task, new AgentRuntimeEvent('permission', 'Permission decision: '.$reply, [
            'permission' => $permission,
            'decision' => $reply,
        ]));
    }

    protected function recordCommand(AgentTask $task, AgentRuntimeEvent $event): void
    {
        $phase = $event->payload['phase'] ?? 'started';

        if ($phase === 'started') {
            AgentTaskCommand::create([
                'agent_task_id' => $task->id,
                'command' => Str::limit((string) ($event->payload['command'] ?? 'unknown'), 2000),
                'cwd' => $task->workspaceRecord?->path,
                'started_at' => now(),
                'status' => AgentTaskCommand::STATUS_RUNNING,
            ]);

            return;
        }

        $command = AgentTaskCommand::where('agent_task_id', $task->id)
            ->where('status', AgentTaskCommand::STATUS_RUNNING)
            ->orderByDesc('created_at')
            ->first();

        if ($command === null) {
            return;
        }

        $failed = (bool) ($event->payload['failed'] ?? false);
        $command->update([
            'exit_code' => $event->payload['exit_code'],
            'duration_ms' => $event->payload['duration_ms'],
            'output_bytes' => $event->payload['output_bytes'] ?? 0,
            'truncated' => (bool) ($event->payload['truncated'] ?? false),
            'status' => $failed ? AgentTaskCommand::STATUS_FAILED : AgentTaskCommand::STATUS_COMPLETED,
        ]);
    }

    protected function recordEvent(AgentTask $task, AgentRuntimeEvent $event, ?string $newStatus = null): AgentTaskEvent
    {
        $seq = (int) (AgentTaskEvent::where('agent_task_id', $task->id)->max('seq') ?? 0) + 1;

        $row = AgentTaskEvent::create([
            'agent_task_id' => $task->id,
            'seq' => $seq,
            'type' => $event->type,
            'summary' => $event->summary !== null ? Str::limit($event->summary, 500) : null,
            'payload' => $event->payload === [] ? null : $event->payload,
        ]);

        if ($newStatus !== null) {
            $task->update(['status' => $newStatus]);
        }

        // Bounded history: drop the oldest beyond the configured cap.
        $cap = (int) config('agent.limits.max_events_per_task', 500);
        $count = AgentTaskEvent::where('agent_task_id', $task->id)->count();
        if ($count > $cap) {
            $oldest = AgentTaskEvent::where('agent_task_id', $task->id)
                ->orderBy('seq')
                ->limit($count - $cap)
                ->pluck('id');
            AgentTaskEvent::whereIn('id', $oldest)->delete();
        }

        return $row;
    }

    protected function sessionBusy(AgentRuntime $runtime, $driver, string $directory, string $sessionId): bool
    {
        try {
            $statuses = $driver->client($runtime)->sessionStatus($directory);
            $ours = $statuses[$sessionId] ?? null;
            $type = is_array($ours) ? ($ours['type'] ?? null) : null;

            return $type === 'busy' || $type === 'retry';
        } catch (\Throwable) {
            return true; // assume busy on status failure — never kill a live task
        }
    }

    protected function fail(AgentTask $task, string $category, string $message): void
    {
        $task->refresh();
        if ($task->isTerminal()) {
            return;
        }

        $task->update([
            'status' => AgentTask::STATUS_FAILED,
            'error_category' => $category,
            'error_message' => Str::limit($message, 500),
            'finished_at' => now(),
        ]);

        $this->recordEvent($task, new AgentRuntimeEvent('error', $message, ['category' => $category]));

        AdminAudit::record('AGENT_TASK_FAILED', $task->project, 'agent_task', $task->id, [
            'code' => $task->code,
            'category' => $category,
        ]);
    }

    protected function nextCode(): string
    {
        $prefix = (string) config('agent.task_code_prefix', 'AGT-');

        $latest = AgentTask::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = $latest !== null ? ((int) Str::after($latest, $prefix)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
