<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectFunction;
use App\Models\ProjectTask;
use App\Models\TaskRun;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\CronService;
use App\Services\ControlPlane\ProjectArtisan;
use App\Services\ControlPlane\SchedulerInfo;
use App\Services\ControlPlane\TaskRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20O Cron Studio: DB-backed scheduled jobs with a cron helper,
 * allowlisted targets (artisan command / server function), run history,
 * enable/disable. NO arbitrary shell commands. The checkout's static
 * schedule stays visible (collapsed) for deployed projects.
 */
class ProjectScheduler extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.scheduler');
    }

    public function getBreadcrumbs(): array
    {
        return ['Scheduler'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('scheduler'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $tasks = ProjectTask::query()->where('project_id', $this->project()->id)->orderBy('name')->get();
        $rows = '';
        foreach ($tasks as $t) {
            $state = $t->enabled ? '<span class="cp-badge is-success">enabled</span>' : '<span class="cp-badge">disabled</span>';
            $last = $t->last_status
                ? '<span class="cp-badge '.($t->last_status === 'ok' ? 'is-success' : 'is-danger').'">'.$t->last_status.'</span>'
                : '—';
            $rows .= '<tr><td><strong>'.e($t->name).'</strong><br><span style="font-size:.7rem">'
                .e($t->target_type.': '.$t->target_ref).'</span></td>'
                .'<td><code>'.e($t->cron).'</code><br><span style="font-size:.7rem">'.e(CronService::describe($t->cron)).'</span></td>'
                .'<td>'.$state.'</td>'
                .'<td>'.e($t->last_run_at?->format('M j, H:i') ?? 'never').'</td>'
                .'<td>'.e($t->next_run_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td>'.$last.'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">No scheduled jobs yet. Create one — e.g. a nightly function call.</td></tr>';
        }
        $history = '';
        foreach (
            TaskRun::query()->whereIn('task_id', $tasks->pluck('id'))
                ->orderByDesc('id')->limit(15)->get() as $run
        ) {
            $history .= '<tr><td>'.e($run->created_at?->format('M j, H:i:s') ?? '—').'</td>'
                .'<td>'.e($tasks->firstWhere('id', $run->task_id)?->name ?? '#'.$run->task_id).'</td>'
                .'<td><span class="cp-badge '.($run->status === 'ok' ? 'is-success' : 'is-danger').'">'.$run->status.'</span></td>'
                .'<td>'.(int) $run->duration_ms.' ms</td>'
                .'<td><code>'.e($run->request_id).'</code></td></tr>';
        }

        $components = [
            Section::make('Scheduled jobs ('.$tasks->count().')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.job')).'</th><th>'.e(__('labels.th_schedule')).'</th><th>'.e(__('labels.state')).'</th><th>'.e(__('labels.th_last_run')).'</th><th>'.e(__('labels.th_next_run')).'</th><th>'.e(__('labels.th_last_status')).'</th>'
                    .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Due jobs run every minute via the console scheduler. '
                    .'Targets: allowlisted artisan commands, server functions. Never shell.</p>'),
            ]),
            Section::make('Run history')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.time')).'</th><th>'.e(__('labels.job')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_duration')).'</th><th>'.e(__('labels.request_id')).'</th>'
                    .'</tr></thead><tbody>'.($history ?: '<tr><td colspan="5">No runs yet.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
        ];

        // Checkout-defined schedule (deployed projects only; collapsed).
        try {
            $info = SchedulerInfo::for($this->project());
            $defined = $info->definedTasks();
            if ($defined !== []) {
                $components[] = Section::make('Project checkout schedule ('.count($defined).')')->schema([
                    TextEntry::make('note')->state('Static tasks from the project checkout routes/console.php. Manage timing through jobs above.'),
                ])->collapsed();
            }
        } catch (\Throwable) {
        }

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'tasks.manage')) {
            return [];
        }
        $functions = ProjectFunction::query()->where('project_id', $this->project()->id)
            ->orderBy('slug')->pluck('slug', 'slug')->all();
        $tasks = ProjectTask::query()->where('project_id', $this->project()->id)
            ->orderBy('name')->pluck('name', 'id')->all();

        return array_filter([
            Action::make('new_job')->label(__('labels.new_scheduled_job'))->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->required()->maxLength(120)->regex('/^[A-Za-z0-9 _\-]{2,120}$/'),
                    Select::make('preset')->label(__('labels.schedule_preset'))->options(
                        array_combine(array_keys(CronService::PRESETS), array_values(CronService::PRESETS))
                    )->live(),
                    TextInput::make('cron')->label(__('labels.cron_expression'))->required()->maxLength(60)
                        ->helperText('5-field cron, e.g. */5 * * * *. Validated + previewed below.'),
                    Select::make('target_type')->label(__('labels.target'))->required()->options([
                        'artisan' => 'Artisan command (allowlisted)', 'function' => 'Server function',
                    ])->live(),
                    Select::make('target_ref')->label(__('labels.command_function'))->required()->options(
                        fn ($get) => $get('target_type') === 'function' ? $functions
                            : array_combine(TaskRunner::ARTISAN_ALLOWLIST, TaskRunner::ARTISAN_ALLOWLIST)
                    ),
                    Toggle::make('enabled')->default(true),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'tasks.manage');
                    abort_unless(CronService::valid($data['cron']), 422, 'Invalid cron expression.');
                    if ($data['target_type'] === 'artisan') {
                        abort_unless(in_array($data['target_ref'], TaskRunner::ARTISAN_ALLOWLIST, true), 403, 'Command not permitted.');
                    }
                    $task = ProjectTask::create([
                        'project_id' => $this->project()->id, 'name' => $data['name'],
                        'cron' => $data['cron'], 'target_type' => $data['target_type'],
                        'target_ref' => $data['target_ref'], 'enabled' => (bool) ($data['enabled'] ?? true),
                    ]);
                    TaskRunner::scheduleNext($task);
                    $this->audit('TASK_CREATED', 'task', $task->id, ['name' => $task->name, 'cron' => $task->cron]);
                    Notification::make()->title("Job {$task->name} created — ".CronService::describe($task->cron))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $tasks === [] ? null : Action::make('run_now')->label(__('labels.run_now'))->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->schema([Select::make('id')->label(__('labels.job'))->required()->options($tasks)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'tasks.manage');
                    $task = ProjectTask::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $result = TaskRunner::run($task, 'owner:'.auth()->id());
                    $note = Notification::make()->title(__('labels.run_frag').$result['status'].' in '.$result['duration_ms'].' ms');
                    $result['status'] === 'ok' ? $note->success()->send() : $note->danger()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $tasks === [] ? null : Action::make('toggle_job')->label(__('labels.enable_disable'))
                ->schema([
                    Select::make('id')->label(__('labels.job'))->required()->options($tasks),
                    Select::make('enabled')->label(__('labels.state'))->required()->options(['1' => 'Enabled', '0' => 'Disabled']),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'tasks.manage');
                    $task = ProjectTask::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $task->forceFill(['enabled' => $data['enabled'] === '1'])->save();
                    TaskRunner::scheduleNext($task);
                    $this->audit($task->enabled ? 'TASK_UPDATED' : 'TASK_DISABLED', 'task', $task->id, ['name' => $task->name]);
                    Notification::make()->title(__('labels.job_frag').($task->enabled ? 'enabled' : 'disabled'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }
}
