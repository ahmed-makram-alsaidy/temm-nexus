<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\Project;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Livewire\Attributes\Url;

/**
 * Phase 43 — Developer Agent workbench.
 *
 * The task interface for the agent runtime platform: create a task against
 * an isolated workspace, watch its live state, review the deterministic
 * diff, approve, apply, verify. All mutations delegate to AgentTaskService
 * (the same service the CLI uses) which enforces server-side authorization
 * at execution time — UI visibility is never the boundary.
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
    }

    /** Projects the current user may run agent tasks on. */
    public function runnableProjects(): array
    {
        $access = Access::for(auth()->user());

        return $access->accessibleProjects()
            ->filter(fn (Project $project) => $access->allows(Capability::AGENTS_RUN, 'project', $project->workspace_id, $project))
            ->mapWithKeys(fn (Project $project) => [$project->id => $project->name.' ('.$project->slug.')'])
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
            $models = app(\App\Services\Agent\AgentRuntimeManager::class)->forRuntime($runtime)->models($runtime);

            $this->modelOptions[$runtimeId] = collect($models)
                ->take(300)
                ->map(fn ($m) => ['value' => $m->canonicalId(), 'label' => $m->canonicalId().($m->name ? ' — '.$m->name : '')])
                ->all();
        } catch (AgentRuntimeException $e) {
            $this->modelOptions[$runtimeId] = [];
            Notification::make()->title(__('agents.models_failed'))->danger()->body($e->getMessage())->send();
        }
    }

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

            Notification::make()->title(__('agents.task_created', ['code' => $task->code]))->success()->send();
        } catch (AgentRuntimeException $e) {
            Notification::make()->title(__('agents.task_create_failed'))->danger()->body($e->getMessage())->send();
        }
    }

    public function approveTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->approve($task, auth()->user());
            Notification::make()->title(__('agents.approval_granted'))->success()->send();
        } catch (AgentRuntimeException $e) {
            Notification::make()->title(__('agents.approval_failed'))->danger()->body($e->getMessage())->send();
        }
    }

    public function rejectTask(): void
    {
        $task = $this->currentTask();

        if ($task === null) {
            return;
        }

        try {
            app(AgentTaskService::class)->reject($task, auth()->user());
            Notification::make()->title(__('agents.approval_rejected'))->success()->send();
        } catch (AgentRuntimeException $e) {
            Notification::make()->title(__('agents.approval_failed'))->danger()->body($e->getMessage())->send();
        }
    }

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
            Notification::make()->title(__('agents.apply_failed'))->danger()->body($e->getMessage())->send();
        }
    }

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
            Notification::make()->title(__('agents.cancel_failed'))->danger()->body($e->getMessage())->send();
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

    /** View data: recent tasks within the caller's project reach. */
    public function taskList(): array
    {
        $access = Access::for(auth()->user());
        $projectIds = $access->accessibleProjectIds();

        return AgentTask::whereIn('project_id', $projectIds)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (AgentTask $t) => [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'status' => $t->status,
                'model' => $t->model,
                'created_at' => $t->created_at?->diffForHumans(),
                'selected' => $t->id === $this->task,
            ])
            ->all();
    }
}
