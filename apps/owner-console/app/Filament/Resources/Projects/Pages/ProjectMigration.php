<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ClientRepository;
use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Models\User;
use App\Services\Access\Capability;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\MigrationCopilot;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use App\Services\ControlPlane\Migration\AnalysisOutcomeClassifier;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\SecretService;
use App\Support\ProductStatus;
use App\Services\ControlPlane\ReadinessService;
use App\Services\Product\JourneyState;
use App\Services\Product\ProjectPulse;
use App\Services\Product\UiPreferenceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * 0.6.0 Phase E (§E12–§E21) — the Migration JOURNEY.
 *
 * The project Migration tab is the canonical migration experience: ONE
 * journey with SIX stage tabs — Connect → Analyze → Plan → Sync → Verify →
 * Cutover — absorbing what used to be scattered surfaces (Migration Center's
 * 8 stacked sections, Cutover, Readiness, the Copilot). The underlying pages
 * keep their routes as deep links; the product presents one journey.
 *
 * STATUS MODEL (docs/STATUS_MODEL.md is binding):
 *  - every stage state is `ProjectPulse::stageState()` output (the canonical
 *    journey model) — nothing on this page re-derives journey state;
 *  - the Sync tab covers the canonical MIGRATE + SYNC stages (run progress
 *    AND live sync); its state is the more severe of the two, an explicitly
 *    documented presentation aggregation, not a new interpretation;
 *  - source connection (Connected / Needs review / Not connected) is its own
 *    concept on the Connect stage and never masquerades as project health.
 *
 * §E28 — no live source probes during render; connection tests and analyses
 * happen through explicit actions. Run/event history loads bounded (fixed
 * limits), never the full raw history.
 */
class ProjectMigration extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'migration';

    /** The six product stages, in journey order. */
    public const STAGES = ['connect', 'analyze', 'plan', 'sync', 'verify', 'cutover'];

    /** §E12 — the stage is contextual navigation, shareable via URL. */
    #[Url]
    public ?string $stage = null;

    public string $error = '';

    public string $errorDetail = '';

    /** Inline new-source form (Connect stage) — the old wizard dead-end class
     * of problem must not reappear here either. */
    public bool $creatingSource = false;

    public array $sourceForm = [
        'display_name' => '', 'type' => 'postgres', 'host' => '', 'port' => '5432',
        'database' => '', 'username' => '', 'password_secret' => '',
    ];

    /** Inline run form (Sync stage) — dry run first, external target behind Advanced. */
    public bool $startingRun = false;

    public string $runMode = 'dry_run';

    public array $targetForm = ['host' => '', 'port' => '5432', 'database' => '', 'username' => '', 'password_secret' => ''];

    public bool $targetDisposable = true;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        if (! in_array($this->stage, self::STAGES, true)) {
            $this->stage = $this->currentProductStage();
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        // §E26 — a viewer may READ the journey; the standard reach check
        // (projects.view at any scope, plus record reachability) governs the
        // page. Every WRITE below re-checks its capability at execution time
        // (migrations.manage / cutover.* / copilot.run), so this stays a
        // read gate, never a write bypass.
        return HasProjectContext::canAccess($parameters);
    }

    public function getTitle(): string|Htmlable
    {
        return __('migration.title');
    }

    public function getSubheading(): ?string
    {
        $activeTab = collect($this->stageTabs())->firstWhere('key', $this->activeStage());

        return __('migration.stage_of', [
            'current' => $activeTab['label'],
            'state' => $activeTab['state']->label(),
        ]);
    }

    /** 0.6.0 Phase B (§B7): Projects → {Project name} → Migration. */
    public function getBreadcrumbs(): array
    {
        return [
            ProjectResource::getUrl('index') => __('nav.projects'),
            static::projectUrl($this->project(), 'overview') => $this->project()->name,
            static::getUrl(['record' => $this->project()]) => __('migration.title'),
        ];
    }

    /** ── Stage resolution (canonical journey → six product stages) ──── */

    public function pulse(): ProjectPulse
    {
        return ProjectPulse::for($this->project());
    }

    /**
     * Where the project actually is: derived from the canonical journey, so
     * reloads and deep links agree with Overview and Home (§E27).
     */
    public function currentProductStage(): string
    {
        $stage = $this->pulse()->currentStage();

        return match ($stage) {
            \App\Services\Product\JourneyStage::CONNECT => 'connect',
            \App\Services\Product\JourneyStage::ANALYZE => 'analyze',
            \App\Services\Product\JourneyStage::PLAN => 'plan',
            // The canonical MIGRATE and SYNC stages are one product tab.
            \App\Services\Product\JourneyStage::MIGRATE, \App\Services\Product\JourneyStage::SYNC => 'sync',
            \App\Services\Product\JourneyStage::VALIDATE => 'verify',
            \App\Services\Product\JourneyStage::CUTOVER => 'cutover',
        };
    }

    public function activeStage(): string
    {
        return in_array($this->stage, self::STAGES, true) ? $this->stage : $this->currentProductStage();
    }

    /**
     * The per-tab journey: canonical stage states presented across the six
     * product stages. MIGRATE + SYNC merge with the documented severity rule.
     *
     * @return list<array{key: string, label: string, state: JourneyState}>
     */
    public function stageTabs(): array
    {
        $journey = [];
        foreach ($this->pulse()->journey() as $entry) {
            $journey[$entry['stage']->value] = $entry['state'];
        }

        $tabState = fn (string ...$stages): JourneyState => collect($stages)
            ->map(fn (string $s) => $journey[$s])
            ->reduce(fn (?JourneyState $worst, JourneyState $state) => $worst === null
                || $this->severity($state) > $this->severity($worst) ? $state : $worst);

        $tabs = [];
        foreach (self::STAGES as $key) {
            $state = match ($key) {
                'connect' => $journey['connect'],
                'analyze' => $journey['analyze'],
                'plan' => $journey['plan'],
                'sync' => $tabState('migrate', 'sync'),
                'verify' => $journey['validate'],
                'cutover' => $journey['cutover'],
            };
            $tabs[] = ['key' => $key, 'label' => __('migration.stage_'.$key), 'state' => $state];
        }

        return $tabs;
    }

    /** Severity for the documented MIGRATE+SYNC merge (BLOCKED outranks all). */
    protected function severity(JourneyState $state): int
    {
        return match ($state) {
            JourneyState::BLOCKED => 5,
            JourneyState::NEEDS_ATTENTION => 4,
            JourneyState::IN_PROGRESS => 3,
            JourneyState::READY => 2,
            JourneyState::COMPLETE => 1,
            default => 0,
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--migration'])->components([
            Html::make(fn (): string => view('filament.projects.migration', [
                'project' => $this->project(),
                'stage' => $this->activeStage(),
                'tabs' => $this->stageTabs(),
                'canManage' => $this->canManage(),
            ] + $this->stageData())->render()),
        ]);
    }

    /** Only the ACTIVE stage's data is resolved — bounded render cost (§E28). */
    protected function stageData(): array
    {
        return match ($this->activeStage()) {
            'connect' => $this->connectStageData(),
            'analyze' => $this->analyzeStageData(),
            'plan' => $this->planStageData(),
            'sync' => $this->syncStageData(),
            'verify' => $this->verifyStageData(),
            'cutover' => $this->cutoverStageData(),
        };
    }

    // ── Connect stage (§E14) ────────────────────────────────────────────

    protected function connectStageData(): array
    {
        $project = $this->project();

        $sources = MigrationSource::where('project_id', $project->id)->orderBy('id')->get()
            ->map(fn (MigrationSource $source) => [
                'id' => $source->id,
                'name' => $source->display_name,
                'connector' => ConnectorRegistry::label($source->effectiveConnectorKey()),
                // §E22 — source connection is ITS OWN concept (status model),
                // labelled exactly as Project Overview labels it.
                'status' => match (true) {
                    strtolower((string) $source->status) === 'ready' => 'connected',
                    in_array(strtolower((string) $source->status), ['error', 'failed'], true) => 'problem',
                    default => 'not_connected',
                },
                'last_tested_at' => $source->last_tested_at,
                'last_error' => (string) ($source->last_error ?? ''),
            ])->values()->all();

        $latestRun = MigrationRun::where('project_id', $project->id)->orderByDesc('id')->first();
        $target = null;
        if ($latestRun !== null && $latestRun->target_connection !== null) {
            $decoded = json_decode($latestRun->target_connection, true);
            $target = $decoded['database'] ?? null;
        }

        return [
            'sources' => $sources,
            'destination' => $target !== null
                ? __('migration.connect_destination_external', ['database' => $target])
                : ($project->db_name ? __('migration.connect_destination_managed', ['database' => $project->db_name]) : __('migration.connect_destination_none')),
            'canManage' => $this->canManage(),
            'creatingSource' => $this->creatingSource,
            'sourceForm' => $this->sourceForm,
            'connectorTypes' => collect(ConnectorRegistry::all())
                ->mapWithKeys(fn ($d, $k) => [$k => $d['name']])->all(),
        ];
    }

    // ── Analyze stage (§E15) ────────────────────────────────────────────

    protected function analyzeStageData(): array
    {
        $project = $this->project();
        $analysis = MigrationAnalysis::where('project_id', $project->id)->orderByDesc('id')->first();
        $this->analysisId = $analysis?->id;

        $warnings = $analysis !== null
            ? AnalysisOutcomeClassifier::present((array) ($analysis->warnings ?? []))
            : [];

        $blockers = [];
        if ($analysis !== null && $analysis->status === 'completed') {
            foreach ($analysis->items()->get(['kind', 'compatibility']) as $item) {
                if ($item->compatibility === \App\Services\ControlPlane\Migration\CompatibilityClassifier::BLOCKED) {
                    $blockers[$item->kind] = ($blockers[$item->kind] ?? 0) + 1;
                }
            }
        }

        return [
            'analysis' => $analysis,
            'analysisProgress' => $this->progressFor($analysis),
            'analysisWarnings' => $warnings,
            'analysisBlockers' => $blockers,
            'analysisTechnical' => $analysis !== null ? AnalysisOutcomeClassifier::technicalFor($analysis) : '',
            'copilot' => $this->copilotPanelData(),
        ];
    }

    /** Real stage telemetry for the view (§E7) — never fabricated. */
    protected function progressFor(?MigrationAnalysis $analysis): array
    {
        if ($analysis === null) {
            return ['running' => false, 'status' => null, 'current' => null, 'stages' => []];
        }

        $telemetry = (array) ($analysis->telemetry ?? []);
        $stages = [];
        foreach (MigrationCenterService::PIPELINE as $key) {
            $entry = (array) ($telemetry['stages'][$key] ?? []);
            $stages[] = [
                'key' => $key,
                'state' => $entry['state'] ?? 'pending',
                'started_at' => $entry['started_at'] ?? null,
                'finished_at' => $entry['finished_at'] ?? null,
            ];
        }

        return [
            'running' => $analysis->status === 'running',
            'status' => $analysis->status,
            'current' => $telemetry['current'] ?? null,
            'stages' => $stages,
        ];
    }

    /** §E21 — the Copilot is a contextual PANEL here, not a destination. */
    protected function copilotPanelData(): array
    {
        $project = $this->project();
        $hasProvider = \App\Models\AiProviderConfig::where('enabled', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))->exists();

        return [
            'available' => $hasProvider,
            'hasAnalysis' => MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->exists(),
        ];
    }

    // ── Plan stage (§E16) ───────────────────────────────────────────────

    protected function planStageData(): array
    {
        $project = $this->project();
        $plan = MigrationPlan::where('project_id', $project->id)->orderByDesc('id')->first();

        $stages = [];
        $items = 0;
        if ($plan !== null) {
            $grouped = $plan->items()->orderBy('stage')->get()->groupBy('stage');
            $items = $plan->items()->count();
            foreach ($grouped as $stage => $group) {
                $stages[] = [
                    'stage' => (string) $stage,
                    // §E16 — human-readable scope; no internal plan IDs.
                    'names' => $group->take(6)->pluck('source_name')->all(),
                    'more' => max(0, $group->count() - 6),
                    'count' => $group->count(),
                ];
            }
        }

        $analysis = MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first();
        $notMoving = [];
        if ($analysis !== null) {
            foreach ($analysis->items()->get(['kind', 'compatibility']) as $item) {
                if (in_array($item->compatibility, [
                    \App\Services\ControlPlane\Migration\CompatibilityClassifier::BLOCKED,
                    \App\Services\ControlPlane\Migration\CompatibilityClassifier::APPLICATION_CONVERSION_REQUIRED,
                ], true)) {
                    $notMoving[$item->kind] = ($notMoving[$item->kind] ?? 0) + 1;
                }
            }
        }

        return [
            'plan' => $plan,
            'planStages' => $stages,
            'planItems' => $items,
            'planNotMoving' => $notMoving,
            'hasAnalysis' => $analysis !== null,
        ];
    }

    // ── Sync stage (§E17) ───────────────────────────────────────────────

    protected function syncStageData(): array
    {
        $project = $this->project();

        // §E28 — bounded reads: the current run + a limited history slice.
        $latest = MigrationRun::where('project_id', $project->id)->orderByDesc('id')->first();
        $latestTarget = null;
        if ($latest !== null && $latest->target_connection !== null) {
            $decoded = json_decode($latest->target_connection, true);
            $latestTarget = $decoded['database'] ?? null;
        }
        $history = MigrationRun::where('project_id', $project->id)->orderByDesc('id')->limit(10)->get();

        $hasPlan = MigrationPlan::where('project_id', $project->id)->exists();
        $source = MigrationSource::where('project_id', $project->id)->orderBy('id')->first();
        $pulse = $this->pulse();

        return [
            'latestRun' => $latest,
            'latestRunTarget' => $latestTarget,
            'latestRunProgress' => $latest !== null ? (array) ($latest->progress ?? []) : [],
            'latestRunLastItem' => $latest !== null
                ? $latest->items()->orderByDesc('id')->first()?->source_name
                : null,
            'runHistory' => $history->map(fn (MigrationRun $run) => [
                'id' => $run->id,
                // Presentation-only translation: stored enums are unchanged,
                // the blade never renders a raw mode/status word.
                'mode' => __('migration.sync_mode_'.($run->dry_run ? 'dry_run' : (string) $run->mode)),
                'status' => ProductStatus::label((string) $run->status),
                'progress' => (array) ($run->progress ?? []),
                'target' => $run->target_connection !== null
                    ? (json_decode($run->target_connection, true)['database'] ?? '—')
                    : '—',
                'finished_at' => $run->finished_at,
            ])->all(),
            'hasPlan' => $hasPlan,
            'sourceName' => $source?->display_name,
            // 0.6.1 — the Live Sync panel speaks whenever a run exists. For a
            // connector that cannot stream it now says "Not supported by this
            // connector" (the Review's own words); the fabricated "Starting"
            // after a dry run is gone because syncState() no longer reports
            // IN_PROGRESS for it. ProjectPulse owns the state — no re-derivation.
            'syncDetail' => $latest !== null ? $pulse->liveSync() : null,
            // 0.6.1 — mirror of MigrationRunManager's GUARD 1: where the
            // platform would refuse every run, the real-transfer option is
            // not offered at all. The guard itself stays in the run manager.
            'productionTarget' => EnvironmentContext::active($project)->type === 'production',
            'startingRun' => $this->startingRun,
            'runMode' => $this->runMode,
            'targetForm' => $this->targetForm,
            'targetDisposable' => $this->targetDisposable,
        ];
    }

    // ── Verify stage (§E18) — validation + readiness, ONE place ─────────

    protected function verifyStageData(): array
    {
        $project = $this->project();
        $env = EnvironmentContext::active($project);
        $summary = ReadinessService::summary($project, $env);

        $groups = ['passed' => [], 'needs_review' => [], 'blocked' => [], 'not_applicable' => []];
        foreach ($summary['checks'] as $check) {
            $groups[match ($check['status']) {
                'green' => 'passed',
                'yellow' => 'needs_review',
                'red' => 'blocked',
                default => 'not_applicable',
            }][] = $check;
        }

        return [
            'groups' => $groups,
            'counts' => (array) ($summary['counts'] ?? []),
            'blockers' => $summary['blockers'] ?? [],
            'canAcknowledge' => CpAccess::allows(auth()->user(), 'readiness.acknowledge'),
        ];
    }

    // ── Cutover stage (§E19) — the accepted gate model, embedded ────────

    protected function cutoverStageData(): array
    {
        $project = $this->project();
        $readiness = \App\Services\Product\CutoverReadiness::for($project);

        return [
            'cutover' => view('filament.projects.cutover', [
                'project' => $project,
                'r' => $readiness,
                'overall' => $readiness->overall(),
                'gates' => $readiness->gates(),
                'issues' => $readiness->blockingIssues(),
                'liveSync' => $readiness->liveSync(),
                'validation' => $readiness->validation(),
                'backup' => $readiness->backup(),
                'rollback' => $readiness->rollback(),
                'finalSync' => $readiness->finalSync(),
                'approvals' => $readiness->approvals(),
                'steps' => $readiness->steps(),
                'plan' => $readiness->plan(),
                'canApprove' => $this->canApprove(),
                'canPreflight' => $this->canPreflight(),
                'ui' => UiPreferenceService::for(auth()->user())->effectiveForComponents(
                    ['cutover.overall', 'cutover.gates', 'cutover.approvals', 'cutover.plan'],
                    $project->workspace?->getKey(),
                    $project->getKey(),
                ),
            ])->render(),
        ];
    }

    // ── Capabilities (§E26) — server-side, mirroring the legacy pages ───

    public function canManage(): bool
    {
        return CpAccess::allows(auth()->user(), 'migrations.manage');
    }

    public function canApprove(): bool
    {
        return \App\Filament\Support\PlatformAccess::current()
            ->allowsProject(Capability::CUTOVER_APPROVE, $this->project());
    }

    public function canPreflight(): bool
    {
        return \App\Filament\Support\PlatformAccess::current()
            ->allowsProject(Capability::CUTOVER_PREFLIGHT, $this->project());
    }

    // ── Header actions ──────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        // The Cutover stage keeps its preflight action (gate model preserved).
        if ($this->activeStage() === 'cutover') {
            return [
                Action::make('runPreflight')
                    ->label(__('labels.run_preflight'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->visible(fn (): bool => $this->canPreflight())
                    ->requiresConfirmation()
                    ->modalHeading(__('labels.run_cutover_preflight'))
                    ->modalDescription(__('labels.creates_a_new_cutover_plan_from_the_curr'))
                    ->modalSubmitActionLabel(__('labels.run_preflight'))
                    ->action(function (): void {
                        if (! $this->canPreflight()) {
                            abort(403, 'Missing capability: '.Capability::CUTOVER_PREFLIGHT);
                        }

                        (new CutoverCenterService)->createPlan($this->project());

                        Notification::make()->title(__('labels.preflight_recorded'))->success()->send();
                    }),
            ];
        }

        return [];
    }

    // ── Actions: Connect stage ──────────────────────────────────────────

    public function openSourceForm(): void
    {
        $this->creatingSource = true;
        $this->error = '';
    }

    public function cancelSourceForm(): void
    {
        $this->creatingSource = false;
        $this->sourceForm = [
            'display_name' => '', 'type' => 'postgres', 'host' => '', 'port' => '5432',
            'database' => '', 'username' => '', 'password_secret' => '',
        ];
    }

    /** Create a read-only source — the same domain logic as the Migration Center. */
    public function createSource(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Missing capability: migrations.manage');
        }

        $this->validate([
            'sourceForm.display_name' => ['required', 'string', 'max:120'],
            'sourceForm.database' => ['required', 'string', 'max:128'],
        ], [], [
            'sourceForm.display_name' => __('migration.connect_source_name'),
            'sourceForm.database' => __('migration.connect_source_database'),
        ]);

        MigrationSource::create([
            'project_id' => $this->project()->id,
            'environment_id' => EnvironmentContext::active($this->project())->id,
            'type' => $this->sourceForm['type'],
            'display_name' => $this->sourceForm['display_name'],
            'source_ref' => $this->sourceForm['database'],
            'connection' => array_filter([
                'host' => $this->sourceForm['host'] ?: null,
                'port' => $this->sourceForm['port'] ?: null,
                'database' => $this->sourceForm['database'],
                'username' => $this->sourceForm['username'] ?: null,
            ]),
            'secret_refs' => $this->sourceForm['password_secret'] ? ['password' => $this->sourceForm['password_secret']] : [],
            'read_only' => true,
            'status' => 'pending',
            'created_by' => auth()->id(),
        ]);

        AdminAudit::record('MIGRATION_SOURCE_CREATED', $this->project(), 'migration_source', null, [
            'name' => $this->sourceForm['display_name'],
        ]);

        Notification::make()->title(__('migration.connect_source_created'))->success()->send();
        $this->cancelSourceForm();
    }

    // ── Actions: Analyze stage (§E7 — one real stage per tick) ──────────

    public function startAnalysis(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Missing capability: migrations.manage');
        }

        $source = MigrationSource::where('project_id', $this->project()->id)->orderBy('id')->first();
        if ($source === null) {
            $this->error = __('migration.error_missing_source');

            return;
        }

        $service = new MigrationCenterService;
        $analysis = $service->beginAnalysis($source);
        $this->analysisId = $analysis->id;
        $this->error = '';
        $this->errorDetail = '';

        $this->tickAnalysis();
    }

    /** Polled by the view while an analysis is running. */
    public function tickAnalysis(): void
    {
        $analysis = MigrationAnalysis::where('project_id', $this->project()->id)
            ->where('status', 'running')->orderByDesc('id')->first();

        if ($analysis === null) {
            return;
        }

        (new MigrationCenterService)->advanceAnalysis($analysis);

        if ($analysis->refresh()->status === 'failed') {
            $this->error = __('migration.analyze_failed_title');
            $this->errorDetail = AnalysisOutcomeClassifier::technicalFor($analysis);
        }
    }

    public function cancelAnalysis(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Missing capability: migrations.manage');
        }

        $analysis = MigrationAnalysis::where('project_id', $this->project()->id)
            ->where('status', 'running')->orderByDesc('id')->first();

        if ($analysis !== null) {
            (new MigrationCenterService)->cancelAnalysis($analysis);
        }
    }

    // ── Actions: Plan stage ─────────────────────────────────────────────

    public function generatePlan(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Missing capability: migrations.manage');
        }

        $analysis = MigrationAnalysis::where('project_id', $this->project()->id)
            ->where('status', 'completed')->orderByDesc('id')->first();

        if ($analysis === null) {
            $this->error = __('migration.error_no_completed_analysis');

            return;
        }

        $plan = (new MigrationCenterService)->generatePlan($analysis);

        Notification::make()->title(__('migration.plan_created', ['count' => $plan->items()->count()]))->success()->send();
    }

    // ── Actions: Sync stage ─────────────────────────────────────────────

    public function openRunForm(): void
    {
        $this->startingRun = true;
        $this->error = '';
    }

    public function cancelRunForm(): void
    {
        $this->startingRun = false;
        $this->targetForm = ['host' => '', 'port' => '5432', 'database' => '', 'username' => '', 'password_secret' => ''];
    }

    /** Start a migration run — dry run first (the Review promise, kept). */
    public function startRun(): void
    {
        if (! $this->canManage()) {
            abort(403, 'Missing capability: migrations.manage');
        }

        $plan = MigrationPlan::where('project_id', $this->project()->id)->orderByDesc('id')->first();
        if ($plan === null) {
            $this->error = __('migration.sync_no_runs_hint');

            return;
        }

        if ($this->runMode === 'external_target') {
            $this->validate([
                'targetForm.host' => ['required', 'string', 'max:255'],
                'targetForm.database' => ['required', 'string', 'max:128'],
            ], [], [
                'targetForm.host' => __('wizard.target_host'),
                'targetForm.database' => __('wizard.target_database'),
            ]);
        }

        try {
            $targetEnvironmentType = EnvironmentContext::active($this->project())->type;
            // Reject production before resolving or vaulting target credentials.
            abort_if($targetEnvironmentType === 'production', 422, 'Migration runs against production are not supported.');
            $target = $this->runTarget($this->project());

            // 0.6.1 — the mode the operator chose is the mode that runs.
            // `real` is the managed destination's real-transfer path: the
            // domain has always allowed it (MigrationRunManager::MODES) —
            // only the journey page never presented it, silently demoting
            // every choice to dry_run/rehearsal. `external_target` stays a
            // DESTINATION selector whose run remains a dry run of the
            // external path, exactly as in 0.6.0.
            $mode = match ($this->runMode) {
                'rehearsal' => 'rehearsal',
                'real' => 'real',
                default => 'dry_run',
            };

            $run = (new MigrationRunManager)->start($plan, [
                'mode' => $mode,
                'target' => $target,
                // The TEMM-managed destination is the real destination — it
                // is never flagged disposable. Reset stays rehearsal-only,
                // so the destructive-reset guard never fires for `real`.
                'target_disposable' => $mode === 'real' ? false : $this->targetDisposable,
                'reset' => $this->runMode === 'rehearsal',
                'target_environment_type' => $targetEnvironmentType,
            ]);
            (new MigrationRunManager)->execute($run);

            Notification::make()->title(__('migration.sync_started_frag', ['status' => $run->status]))
                ->success($run->status === 'completed')
                ->danger($run->status === 'failed')
                ->warning(! in_array($run->status, ['completed', 'failed']))
                ->send();

            $this->cancelRunForm();
        } catch (\Throwable $e) {
            // §E23 — what failed, what it means, what to do; detail disclosed.
            $this->error = __('migration.error_generic_title').' '.__('migration.error_generic_body');
            $this->errorDetail = class_basename($e).': '.$e->getMessage();

            report($e);
        }
    }

    /** TEMM-managed target unless the operator filled the external form. */
    protected function runTarget($project): array
    {
        if ($this->runMode !== 'external_target') {
            // 0.6.1 — the managed destination resolves exactly like the rest
            // of the platform (ProjectConnectionManager): the per-project
            // endpoint override, then PROJECT_DB_HOST/PORT, then the compose
            // service default. No second endpoint interpretation.
            $target = array_filter([
                'host' => $project->db_host ?: env('PROJECT_DB_HOST', 'postgres'),
                'port' => $project->db_port ?: env('PROJECT_DB_PORT', '5432'),
                'database' => $project->db_name ?: null,
            ]);

            // A real transfer must AUTHENTICATE. The managed project DB's
            // least-privilege role lives in the per-project env vars written
            // by scripts/postgres/create-project-db.sh — the same source
            // ProjectConnectionManager reads. The password is vaulted for the
            // run and travels as a secret REF: the value itself is never
            // persisted (target_connection is stored redacted). Without
            // these vars the run fails closed before adapter defaults can apply.
            if ($this->runMode === 'real') {
                $prefix = ProjectConnectionManager::envPrefix($project);
                $username = env($prefix.'USERNAME');
                $password = env($prefix.'PASSWORD');
                if (! $project->db_name || ! $username || $password === null || $password === false || $password === '') {
                    throw new \RuntimeException("Database credentials for project [{$project->slug}] are not configured.");
                }
                SecretService::create($project, 'MANAGED_TARGET_PASSWORD', (string) $password, 'managed real-transfer target credential');
                $target['username'] = $username;
                $target['secret_refs'] = ['password' => 'MANAGED_TARGET_PASSWORD'];
            }

            return $target;
        }

        return array_filter([
            'host' => $this->targetForm['host'] ?: null,
            'port' => $this->targetForm['port'] ?: null,
            'database' => $this->targetForm['database'] ?: null,
            'username' => $this->targetForm['username'] ?: null,
            'secret_refs' => $this->targetForm['password_secret'] ? ['password' => $this->targetForm['password_secret']] : [],
        ]);
    }

    // ── Actions: Verify stage ───────────────────────────────────────────

    public function evaluateReadiness(): void
    {
        $summary = ReadinessService::evaluate($this->project(), EnvironmentContext::active($this->project()));
        $counts = $summary['counts'] ?? [];

        Notification::make()
            ->title(__('migration.verify_summary_frag', [
                'green' => $counts['green'] ?? 0,
                'yellow' => $counts['yellow'] ?? 0,
                'red' => $counts['red'] ?? 0,
            ]))
            ->warning(($counts['red'] ?? 0) > 0)
            ->success(($counts['red'] ?? 0) === 0)
            ->send();
    }

    // ── Actions: Copilot panel (§E21 — existing safe-action rules) ──────

    public function explainBlockers(): void
    {
        if (! CpAccess::allows(auth()->user(), 'copilot.run')) {
            abort(403, 'Missing capability: copilot.run');
        }

        try {
            $analysis = MigrationAnalysis::where('project_id', $this->project()->id)
                ->where('status', 'completed')->orderByDesc('id')->first();

            if ($analysis === null) {
                $this->error = __('migration.error_no_completed_analysis');

                return;
            }

            (new MigrationCopilot(new AiGateway))->run($this->project(), 'explain_blockers', [
                'analysis' => $analysis,
                'repository' => ClientRepository::where('project_id', $this->project()->id)->orderByDesc('id')->first(),
            ]);

            Notification::make()->title(__('migration.copilot_done'))->success()->send();
        } catch (\Throwable $e) {
            $this->error = __('migration.error_generic_title');
            $this->errorDetail = class_basename($e).': '.$e->getMessage();

            report($e);
        }
    }
}
