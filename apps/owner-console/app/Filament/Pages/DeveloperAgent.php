<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\AgentTaskEvent;
use App\Models\Project;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\Agent\AgentStatusPresenter;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * 0.6.0 Phase G — the Developer Agent workbench, STATUS-FIRST (audit §10).
 *
 * The page answers six questions before anything technical:
 *   What task did I ask for? / What is the agent doing now? / What changed?
 *   Did tests pass? / Does anything need my approval? / What happens next?
 *
 * Structure: TASK → STATUS → WHAT CHANGED → TESTS → YOUR DECISION →
 * Activity (human sentences) → Technical details (session, commands, raw
 * events, fingerprints — Level 3/4 content, never the default face).
 *
 * All mutations delegate to AgentTaskService (the same service the CLI uses),
 * which enforces server-side authorization at execution time — UI visibility
 * is never the boundary. No session/runtime internals appear on the default
 * face; the stored task enums are never rewritten, only presented.
 */
class DeveloperAgent extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $slug = 'developer-agent';

    protected string $view = 'filament.pages.developer-agent';

    #[Url]
    public ?string $task = null;

    public array $taskForm = [
        'project_id' => null,
        'runtime_id' => null,
        'model' => null,
        'prompt' => '',
        'title' => '',
    ];

    /** @var array<string, list<array<string, mixed>>> discovered models per runtime id. */
    public array $modelOptions = [];

    /** The task-history filter (§G20): all|active|ready|completed|failed|cancelled. */
    public string $historyFilter = 'all';

    /** An optional short reason for a rejection (§G12). */
    public string $rejectNote = '';

    /** Mount-time runtime probe, re-checked at most every 60 s across polls. */
    public ?array $runtimeCheck = null;

    public static function canAccess(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AGENTS_VIEW);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('agents.nav_workbench');
    }

    public function getHeading(): string
    {
        return __('agents.workbench_title');
    }

    public function getSubheading(): ?string
    {
        return __('agents.workbench_subtitle');
    }

    public function mount(): void
    {
        $this->taskForm['project_id'] = array_key_first($this->runnableProjects());
        $this->taskForm['runtime_id'] = AgentRuntime::where('enabled', true)->orderByDesc('created_at')->value('id');
        $this->loadModels();
        $this->checkRuntime();
    }

    // ── Runtime state (§G3, §G4) ────────────────────────────────────────

    /**
     * The quiet runtime state: connected (with the detected OpenCode version)
     * / unavailable / needs configuration. Re-probed at most once a minute —
     * the check is one bounded health call, not part of every render (§G28).
     *
     * @return array{state: string, label: string, version: ?string}
     */
    public function runtimeState(): array
    {
        if ($this->runtimeCheck === null || $this->runtimeCheck['checked_at']->lt(now()->subSeconds(60))) {
            $this->checkRuntime();
        }

        return $this->runtimeCheck['state'];
    }

    protected function checkRuntime(): void
    {
        $runtime = AgentRuntime::where('enabled', true)->orderByDesc('created_at')->first();

        if ($runtime === null) {
            $state = ['state' => 'needs_configuration', 'label' => __('agents.runtime_needs_configuration'), 'version' => null];
        } else {
            try {
                $connection = app(AgentRuntimeManager::class)->forRuntime($runtime)->testConnection($runtime);
                $state = $connection->ok
                    ? ['state' => 'connected', 'label' => __('agents.runtime_connected'), 'version' => $connection->version]
                    : ['state' => 'unavailable', 'label' => __('agents.runtime_unavailable'), 'version' => null];
            } catch (\Throwable) {
                $state = ['state' => 'unavailable', 'label' => __('agents.runtime_unavailable'), 'version' => null];
            }
        }

        $this->runtimeCheck = ['state' => $state, 'checked_at' => now()];
    }

    /** True when the workbench can actually create tasks right now. */
    public function canStartTasks(): bool
    {
        return count($this->runnableProjects()) > 0
            && AgentRuntime::where('enabled', true)->exists();
    }

    /** May the current user configure runtimes? Drives the recovery CTA (§G3). */
    public function canConfigureAgents(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AGENTS_CONFIGURE);
    }

    /** Projects the current user may run agent tasks on. */
    public function runnableProjects(): array
    {
        $access = Access::for(auth()->user());

        return $access->accessibleProjects()
            ->filter(fn (Project $project) => $access->allows(Capability::AGENTS_RUN, 'project', $project->workspace_id, $project))
            ->mapWithKeys(fn (Project $project) => [$project->id => $project->name])
            ->all();
    }

    public static function runtimes(): array
    {
        return AgentRuntime::where('enabled', true)->orderByDesc('created_at')->get()->all();
    }

    public function updatedTaskFormProjectId(): void
    {
        $this->taskForm['model'] = null;
    }

    public function updatedTaskFormRuntimeId(): void
    {
        $this->taskForm['model'] = null;
        $this->loadModels();
    }

    /** Real model discovery from the runtime — no invented labels (§G23). */
    public function loadModels(): void
    {
        $runtimeId = $this->taskForm['runtime_id'];

        if ($runtimeId === null || isset($this->modelOptions[$runtimeId])) {
            return;
        }

        $runtime = AgentRuntime::find($runtimeId);

        if ($runtime === null) {
            return;
        }

        try {
            $models = app(AgentRuntimeManager::class)->forRuntime($runtime)->models($runtime);

            $this->modelOptions[$runtimeId] = collect($models)
                ->take(300)
                ->map(fn ($m) => ['value' => $m->canonicalId(), 'label' => $m->canonicalId().($m->name ? ' — '.$m->name : '')])
                ->all();
        } catch (AgentRuntimeException $e) {
            $this->modelOptions[$runtimeId] = [];
            Notification::make()->title(__('agents.models_failed'))->danger()->body(AgentStatusPresenter::errorTitle($e->category))->send();
        }
    }

    // ── Task creation (§G2) ─────────────────────────────────────────────

    public function createTask(): void
    {
        $this->validate([
            'taskForm.project_id' => ['required'],
            'taskForm.runtime_id' => ['required'],
            'taskForm.prompt' => ['required', 'string', 'min:10'],
            'taskForm.title' => ['nullable', 'string', 'max:200'],
        ], [], [
            'taskForm.prompt' => __('agents.field_prompt'),
            'taskForm.project_id' => __('agents.field_project'),
            'taskForm.runtime_id' => __('agents.field_runtime'),
        ]);

        $project = Project::find($this->taskForm['project_id']);
        $runtime = AgentRuntime::find($this->taskForm['runtime_id']);

        if ($project === null || $runtime === null) {
            Notification::make()->title(__('agents.task_create_failed'))->danger()->send();

            return;
        }

        try {
            $task = app(AgentTaskService::class)->createTask(
                auth()->user(),
                $project,
                $runtime,
                $this->taskForm['model'],
                (string) $this->taskForm['prompt'],
                $this->taskForm['title'] !== '' ? (string) $this->taskForm['title'] : null,
            );

            $this->taskForm['prompt'] = '';
            $this->taskForm['title'] = '';
            $this->task = $task->id;
            $this->historyFilter = 'all';

            Notification::make()->title(__('agents.task_created', ['code' => $task->code]))->success()->send();
        } catch (AgentRuntimeException $e) {
            // §G27 — the default face names the classified problem and the
            // next step; the raw runtime text stays in the logs, never in a
            // notification body.
            Notification::make()
                ->title(__('agents.task_create_failed'))
                ->danger()
                ->body(AgentStatusPresenter::errorTitle($e->category))
                ->send();
        }
    }

    /** §G19 — explicit re-use of a failed task's prompt. Nothing silent. */
    public function newTaskFromPrompt(string $taskId): void
    {
        $task = AgentTask::find($taskId);

        if ($task === null || ! Access::for(auth()->user())->canReachProject($task->project_id)) {
            return;
        }

        $this->taskForm['project_id'] = $task->project_id;
        $this->taskForm['runtime_id'] = $task->agent_runtime_id;
        $this->taskForm['model'] = $task->model;
        $this->taskForm['title'] = $task->title !== null ? Str::limit($task->title, 200, '') : '';
        $this->taskForm['prompt'] = (string) $task->prompt;
        $this->task = null;
        $this->modelOptions = [];

        $this->loadModels();
    }

    // ── Decision actions (§G10–§G13) ────────────────────────────────────

    /**
     * Approve AND apply in one human decision. The service authorizes each
     * step independently: an approver without AGENTS_APPLY gets an honest
     * state (approved; an operator with apply permission must run the apply).
     */
    public function approveAndApplyTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        $service = app(AgentTaskService::class);

        try {
            $service->approve($task, auth()->user());
        } catch (AgentRuntimeException $e) {
            $this->failureNotice(__('agents.approval_failed'), $e);

            return;
        }

        try {
            $service->apply($task, auth()->user());
            Notification::make()->title(__('agents.applied'))->success()->send();
        } catch (AgentRuntimeException $e) {
            if (str_contains((string) $e->getMessage(), 'fingerprint') || str_contains((string) $e->getMessage(), 'stale refused')) {
                $this->failureNotice(__('agents.apply_failed'), $e);
            } else {
                Notification::make()
                    ->title(__('agents.apply_pending'))
                    ->warning()
                    ->body(AgentStatusPresenter::errorTitle($e->category))
                    ->send();
            }
        }
    }

    /** Approve without applying — for an approver whose role does not include AGENTS_APPLY. */
    public function approveTaskOnly(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->approve($task, auth()->user());
            Notification::make()->title(__('agents.approval_granted'))->success()->send();
        } catch (AgentRuntimeException $e) {
            $this->failureNotice(__('agents.approval_failed'), $e);
        }
    }

    /**
     * §G8 — the tamper/stale refusals get their exact re-review copy; every
     * other refusal names the classified problem (§G27). Raw runtime text
     * never reaches the notification face.
     */
    protected function failureNotice(string $title, AgentRuntimeException $e): void
    {
        $message = (string) $e->getMessage();

        if (str_contains($message, 'fingerprint') || str_contains($message, 'stale refused')) {
            Notification::make()->title(__('agents.stale_title'))->warning()->body(__('agents.stale_body'))->send();

            return;
        }

        Notification::make()->title($title)->danger()->body(AgentStatusPresenter::errorTitle($e->category))->send();
    }

    /** §G12 — decline. Nothing is applied; the task and its history remain. */
    public function rejectTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->reject(
                $task,
                auth()->user(),
                $this->rejectNote !== '' ? mb_substr($this->rejectNote, 0, 500) : null,
            );
            $this->rejectNote = '';
            Notification::make()->title(__('agents.approval_rejected'))->success()->send();
        } catch (AgentRuntimeException $e) {
            Notification::make()->title(__('agents.approval_failed'))->danger()->body(AgentStatusPresenter::errorTitle($e->category))->send();
        }
    }

    /**
     * Apply an ALREADY-approved changeset (the approve-without-apply path and
     * the cross-interface case: approved via CLI, applied here). Never shown
     * before an approval exists (§G10).
     */
    public function applyTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->apply($task, auth()->user());
            Notification::make()->title(__('agents.applied'))->success()->send();
        } catch (AgentRuntimeException $e) {
            $this->failureNotice(__('agents.apply_failed'), $e);
        }
    }

    /** §G17 — cancel. Honest copy; existing cancellation semantics; no rollback invention. */
    public function cancelTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->cancel($task, auth()->user());
            Notification::make()->title(__('agents.cancelled'))->success()->send();
        } catch (AgentRuntimeException $e) {
            Notification::make()->title(__('agents.cancel_failed'))->danger()->body(AgentStatusPresenter::errorTitle($e->category))->send();
        }
    }

    public function selectTask(?string $id): void
    {
        $this->task = $id;
    }

    public function currentTask(): ?AgentTask
    {
        if ($this->task === null) {
            return null;
        }

        $task = AgentTask::find($this->task);

        if ($task === null) {
            return null;
        }

        $access = Access::for(auth()->user());

        if (! $access->canReachProject($task->project_id)) {
            return null; // never leak cross-project tasks
        }

        return $task;
    }

    // ── Task history (§G20) ─────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function taskList(): array
    {
        $access = Access::for(auth()->user());
        $projectIds = $access->accessibleProjectIds();

        $statuses = AgentStatusPresenter::FILTERS[$this->historyFilter] ?? null;

        $query = AgentTask::query()
            ->whereIn('project_id', $projectIds)
            ->orderByDesc('created_at')
            ->limit(25);

        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        $projectNames = $access->accessibleProjects()->mapWithKeys(fn (Project $p) => [$p->id => $p->name]);

        return $query->get()
            ->map(function (AgentTask $t) use ($projectNames) {
                $human = AgentStatusPresenter::status($t);

                return [
                    'id' => $t->id,
                    'code' => $t->code,
                    'title' => $t->title,
                    'project' => $projectNames[$t->project_id] ?? null,
                    'status_label' => $human['label'],
                    'status_tone' => $human['tone'],
                    'attention' => in_array($t->status, [AgentTask::STATUS_AWAITING_APPROVAL, AgentTask::STATUS_FAILED, AgentTask::STATUS_STALE], true),
                    'model' => $t->model,
                    'last_active' => ($t->updated_at ?? $t->created_at)?->diffForHumans(),
                    'selected' => $t->id === $this->task,
                ];
            })
            ->all();
    }

    /** The decision boundary for the selected task (§G25) — presentation only. */
    public function decisionRights(AgentTask $task): array
    {
        $access = Access::for(auth()->user());
        $scope = ['project', $task->workspace_id, $task->project_id];

        return [
            'canApprove' => $access->allows(Capability::AGENTS_APPROVE, ...$scope),
            'canApply' => $access->allows(Capability::AGENTS_APPLY, ...$scope),
            'canCancel' => $access->allows(Capability::AGENTS_CANCEL, ...$scope),
            'hasLiveApproval' => $task->approvals()
                ->where('status', \App\Models\AgentApproval::STATUS_APPROVED)
                ->whereNull('consumed_at')
                ->exists(),
        ];
    }

    /**
     * The bounded detail bundle for the selected task: human status, changeset
     * summary + file list, verification checks, and a HUMANIZED bounded
     * timeline. Raw events, commands and internals are NOT loaded here — the
     * Technical details view loads its own bounded slices on demand (§G28).
     *
     * @return array<string, mixed>|null
     */
    public function detailData(): ?array
    {
        $task = $this->currentTask();

        if ($task === null) {
            return null;
        }

        $human = AgentStatusPresenter::status($task);
        $changeset = AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first();
        $verification = $task->verifications()->latest('created_at')->first();
        $events = $task->events()->orderByDesc('seq')->limit(30)->get()->reverse()->values();
        $eventCount = $task->events()->count();

        $timeline = $events
            ->map(fn (AgentTaskEvent $e) => [
                'label' => AgentStatusPresenter::timelineLabel($e),
                'type' => $e->type,
                'at' => $e->created_at?->format('H:i'),
            ])
            ->filter(fn (array $row) => $row['label'] !== null)
            ->values()
            ->all();

        $project = $task->project;

        return [
            'task' => $task,
            'status' => $human,
            'project_name' => $project?->name,
            'runtime_name' => $task->runtime?->display_name,
            'model' => $task->model,
            'changeset' => $changeset,
            'changed_paths' => $changeset !== null
                ? \App\Services\Agent\AgentChangesetService::changedPaths((string) $changeset->diff)
                : [],
            'verification' => $verification,
            'timeline' => $timeline,
            'event_count' => $eventCount,
            'decision' => $this->decisionRights($task),
            'creator' => $task->createdBy?->name,
        ];
    }
}
