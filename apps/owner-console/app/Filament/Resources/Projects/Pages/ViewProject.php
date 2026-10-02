<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Pages\WorkspaceDetail;
use App\Filament\Pages\Workspaces;
use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BackupRecord;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ControlPlanePaths;
use App\Services\ControlPlane\ProjectHealthService;
use App\Services\ControlPlane\ProjectOverviewData;
use App\Services\Product\JourneyStage;
use App\Services\Product\ProjectPulse;
use App\Services\Product\UiPreferenceService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Redis;

class ViewProject extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    /** Breadcrumb tail: the page heading already shows the project name. */
    protected static ?string $breadcrumb = 'Overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /**
     * 0.4.0 Phase D (§9) — the Project Overview is rebuilt around the MIGRATION
     * JOURNEY, not around infrastructure.
     *
     * v0.3.0's first screen led with database reachability, Pulse API request
     * counts and queue depth, and showed no migration progress, no current
     * stage, and no readiness at all. The mission's own example for this screen
     * is: name, source/target, progress %, current stage, health, Live Sync,
     * last backup, readiness, and ONE primary CTA.
     *
     * All of the old telemetry is preserved — it moves under "Advanced
     * details", which is progressive disclosure, not deletion (§14).
     */
    public function getTitle(): string|Htmlable
    {
        return $this->project()->name;
    }

    public function getSubheading(): ?string
    {
        $p = $this->project();
        $parts = array_filter([
            $p->workspace?->name,
            strtoupper((string) ($p->environment ?? 'local')),
            $p->api_domain ?: $p->slug,
        ]);

        return implode(' · ', $parts) ?: null;
    }

    /**
     * Breadcrumbs follow the three-scope model: Platform -> Workspace -> Project.
     *
     * NOTE ON THE SHAPE: Laravel/Filament expect `[url => label]`, NOT
     * `[label => url]`. Getting this backwards renders the URL as the visible
     * text and puts the label in the href, which is exactly what an earlier
     * revision of this page did.
     */
    public function getBreadcrumbs(): array
    {
        $crumbs = [];

        $workspace = $this->project()->workspace;
        if ($workspace) {
            $crumbs[Workspaces::getUrl()] = 'Clients & Workspaces';
            $crumbs[WorkspaceDetail::urlFor($workspace)] = $workspace->name;
        } else {
            // Ungrouped legacy project: no workspace crumb to show.
            $crumbs[ProjectResource::getUrl('index')] = 'Projects';
        }

        $crumbs[static::getUrl(['record' => $this->project()])] = 'Overview';

        return $crumbs;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--overview'])->components([
            $this->subnavSection('overview'),
            Html::make(fn (): string => view('filament.projects.overview', $this->overviewViewData())->render()),
        ]);
    }

    /**
     * Everything the overview view needs, resolved in PHP.
     *
     * The view is rendered through `view()`, so the page's protected helpers are
     * NOT in scope inside it. Passing an explicit array keeps the template free
     * of logic and makes a missing value a visible null rather than an
     * "Undefined variable" 500 at render time.
     *
     * @return array<string, mixed>
     */
    protected function overviewViewData(): array
    {
        $pulse = $this->pulse();
        $project = $this->project();

        return [
            'project' => $project,
            'pulse' => $pulse,
            'journey' => $pulse->journey(),
            'current' => $pulse->currentStage(),
            'progress' => $pulse->progressPercent(),
            'overall' => $pulse->overallState(),
            'sync' => $pulse->liveSync(),
            'backup' => $pulse->backup(),
            'blockers' => $pulse->blockers(),
            'warnings' => $pulse->warnings(),
            'primaryAction' => $this->primaryAction(),
            'legacy' => $this->legacyTelemetry(),
            'advancedUrl' => $this->projectAdvancedUrl(),
            // Phase I — this user's appearance preferences for the overview
            // components, resolved at project scope (falling back to their
            // global preference).
            'ui' => UiPreferenceService::for(auth()->user())->effectiveForComponents(
                [
                    'project.overview.progress',
                    'project.overview.facts',
                    'project.overview.journey',
                    'project.overview.attention',
                    'project.overview.activity',
                    'project.overview.advanced',
                ],
                $project->workspace?->getKey(),
                $project->getKey(),
            ),
        ];
    }

    /** Deep link to the existing technical surface, so depth stays one click away. */
    protected function projectAdvancedUrl(): ?string
    {
        try {
            return ProjectResource::hasPage('db-advanced')
                ? static::projectUrl($this->project(), 'db-advanced')
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cached per request so the journey is not recomputed for every block. */
    private ?ProjectPulse $pulseCache = null;

    protected function pulse(): ProjectPulse
    {
        return $this->pulseCache ??= ProjectPulse::for($this->project());
    }

    /**
     * The v0.3.0 telemetry, gathered for the Advanced disclosure.
     *
     * Kept deliberately tolerant: every figure degrades to null/'—' rather than
     * throwing, because an optional subsystem being absent must not take down
     * the project's home screen.
     *
     * @return array<string, mixed>
     */
    protected function legacyTelemetry(): array
    {
        try {
            $data = ProjectOverviewData::for($this->project());
        } catch (\Throwable) {
            return ['available' => false];
        }

        $health = $data['health'] + ['ok' => null, 'database' => [], 'redis' => [], 'application' => []];
        $m = $data['metrics'];
        $pulse = is_array($m['pulse'] ?? null) ? $m['pulse'] : [];
        $db = is_array($health['database'] ?? null) ? $health['database'] : [];
        $redis = is_array($health['redis'] ?? null) ? $health['redis'] : [];
        /** @var BackupRecord|null $lastBackup */
        $lastBackup = $m['last_backup'] ?? null;

        return [
            'available' => true,
            'health_ok' => $health['ok'],
            'database_reachable' => $db['reachable'] ?? null,
            'database_connections' => $db['connections'] ?? null,
            'pulse_requests' => array_sum(array_map('intval', $pulse)),
            'pulse_errors' => (int) ($pulse['exception'] ?? 0) + (int) ($pulse['slow_request'] ?? 0),
            'storage_bytes' => is_numeric($m['storage'] ?? null) ? (int) $m['storage'] : null,
            'queue_pending' => is_numeric($redis['pending_jobs'] ?? null) ? (int) $redis['pending_jobs'] : null,
            'queue_failed' => is_numeric($m['queue_failed'] ?? null) ? (int) $m['queue_failed'] : null,
            'app_users' => is_numeric($m['app_users'] ?? null) ? (int) $m['app_users'] : null,
            'db_size' => is_numeric($m['db_size'] ?? null) ? (int) $m['db_size'] : null,
            'functions' => (int) ($m['functions'] ?? 0),
            'last_backup_status' => $lastBackup?->status,
            'last_backup_at' => $lastBackup?->finished_at,
            'bytes' => fn (?int $b): string => ProjectOverviewData::bytes($b),
            'activity' => $data['activity'] ?? [],
        ];
    }

    /**
     * The single primary action (§9).
     *
     * A dashboard with no primary CTA was a baseline finding on both Home and
     * this page. The CTA follows the journey: whatever the NEXT unfinished
     * stage is, that is what the button does.
     *
     * @return array{label: string, url: string, description: string}
     */
    public function primaryAction(): array
    {
        $project = $this->project();
        $stage = $this->pulse()->currentStage();

        $page = match ($stage) {
            JourneyStage::CONNECT => 'connect',
            JourneyStage::ANALYZE, JourneyStage::PLAN, JourneyStage::MIGRATE => 'migration-center',
            JourneyStage::SYNC => 'monitoring',
            JourneyStage::VALIDATE => 'readiness',
            // 0.4.0 Phase E — the journey's final step is its own screen.
            JourneyStage::CUTOVER => 'cutover',
        };

        if (! ProjectResource::hasPage($page)) {
            $page = 'overview';
        }

        $label = match ($stage) {
            JourneyStage::CONNECT => 'Connect a source',
            JourneyStage::ANALYZE => 'Analyze the source',
            JourneyStage::PLAN => 'Review the plan',
            JourneyStage::MIGRATE => 'Continue migration',
            JourneyStage::SYNC => 'Check Live Sync',
            JourneyStage::VALIDATE => 'Run validation',
            JourneyStage::CUTOVER => 'Review cutover',
        };

        return [
            'label' => $label,
            'url' => static::projectUrl($project, $page),
            'description' => $stage->description(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('health_check')
                    ->label('Run health check')
                    ->icon('heroicon-o-heart')
                    ->action(function () {
                        $health = ProjectHealthService::for($this->project())->check();
                        $this->project()->update(['health_status' => $health['ok'] ? 'healthy' : 'unhealthy']);
                        AdminAudit::record('PROJECT_HEALTH_CHECKED', $this->project(), null, null, ['ok' => $health['ok']]);
                        $note = Notification::make()->title($health['ok'] ? 'Project healthy' : 'Project unhealthy — see Overview');
                        $health['ok'] ? $note->success()->send() : $note->danger()->send();
                        $this->redirect(static::getUrl(['record' => $this->project()]));
                    }),
                Action::make('clear_cache')
                    ->label('Clear application cache')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalDescription('Flushes this project\u2019s Redis namespace (cache + queues state for the prefix). Database untouched. Audit logged.')
                    ->action(function () {
                        $prefix = ($this->project()->redis_prefix ?? $this->project()->slug).':';
                        $deleted = 0;
                        foreach (Redis::connection()->keys($prefix.'*') as $key) {
                            Redis::connection()->del($key);
                            $deleted++;
                        }
                        $this->audit('PROJECT_CACHE_CLEARED', null, null, ['keys_deleted' => $deleted, 'prefix' => $prefix]);
                        Notification::make()->title("Cleared {$deleted} keys under {$prefix}")->success()->send();
                    }),
                Action::make('maintenance_on')
                    ->label('Enable maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->visible(fn () => ! $this->project()->maintenance_mode)
                    ->requiresConfirmation()
                    ->action(fn () => $this->setMaintenance(true)),
                Action::make('maintenance_off')
                    ->label('Disable maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('danger')
                    ->visible(fn () => (bool) $this->project()->maintenance_mode)
                    ->requiresConfirmation()
                    ->action(fn () => $this->setMaintenance(false)),
            ])->label('Actions')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    protected function setMaintenance(bool $on): void
    {
        $downFile = ControlPlanePaths::projectDir($this->project()->slug).'/storage/framework/down';
        if ($on) {
            @mkdir(dirname($downFile), 0755, true);
            file_put_contents($downFile, json_encode(['message' => 'Maintenance via control plane', 'time' => time()]));
            $this->project()->update(['maintenance_mode' => true]);
            $this->audit('PROJECT_MAINTENANCE_ENABLED');
            Notification::make()->title('Maintenance mode enabled')->warning()->send();
        } else {
            @unlink($downFile);
            $this->project()->update(['maintenance_mode' => false]);
            $this->audit('PROJECT_MAINTENANCE_DISABLED');
            Notification::make()->title('Maintenance mode disabled')->success()->send();
        }
        $this->redirect(static::getUrl(['record' => $this->project()]));
    }
}
