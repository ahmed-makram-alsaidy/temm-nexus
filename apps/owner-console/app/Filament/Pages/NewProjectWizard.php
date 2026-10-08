<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\NexusAi;
use App\Filament\Support\PlatformAccess;
use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Models\ProjectWizardDraft;
use App\Models\Workspace;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;
use App\Services\ControlPlane\Connectors\Contracts\Connector;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\EnvironmentService;
use App\Services\ControlPlane\Migration\AnalysisOutcomeClassifier;
use App\Services\ControlPlane\Migration\CompatibilityClassifier;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseProvisioner;
use App\Services\ControlPlane\SecretVaultService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * 0.6.0 Phase E — the New Project wizard, redesigned as ONE journey:
 *
 *   1. Project      — name, workspace/client (inline create), environment
 *   2. Source       — connector + connection in one step; Advanced collapsed
 *   3. Destination  — TEMM-managed (recommended) or external PostgreSQL
 *   4. Analyze      — real staged progress (Preparing → Inspecting → …)
 *   5. Review       — deterministic readiness + Start Migration
 *
 * §E3 — state persists server-side from the FIRST step (ProjectWizardDraft),
 * so "stop and finish later" is true everywhere. Secrets are never written to
 * the draft: they follow the existing write-once vault handling once a source
 * exists, and are requested again only when a resume genuinely needs them.
 *
 * §E2 — the workspace dead end is gone: the wizard creates a workspace
 * inline without abandoning the wizard or losing entered fields.
 *
 * Engine internals (TargetAdapter, TransformPipeline, checkpoints, CDC
 * vocabulary) stay under the hood. Authorization mirrors the control plane:
 * page requires the user to be able to create a project SOMEWHERE; the chosen
 * workspace is re-verified against the user's accessible workspaces on every
 * continue; mutating actions re-check their capability at execution time.
 */
class NewProjectWizard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $slug = 'new-project';

    protected string $view = 'filament.pages.new-project-wizard';

    // Reached from Home / Projects / Workspace pages, not from the sidebar —
    // the wizard is an ACTION, not a destination (mirrors WorkspaceDetail).
    protected static bool $shouldRegisterNavigation = false;

    /** §E4 — five steps. The old Source and Connection steps are ONE step. */
    public const STEPS = ['project', 'source', 'destination', 'analyze', 'review'];

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

    /**
     * §E4 — connection-field presentation order. Required/common fields come
     * first in this order regardless of the connector's declaration order
     * (the v0.5.0 defect was Password rendering before Host).
     */
    public const FIELD_ORDER = ['host', 'port', 'database', 'username', 'password'];

    /**
     * §E4 — expert fields that live behind the Advanced disclosure. Anything
     * not required and not in the primary order joins them.
     */
    public const ADVANCED_FIELDS = ['ssl_mode', 'schemas', 'batch_size'];

    /** Server-side wizard state (survives step navigation; safe values only). */
    public array $state = [
        'name' => '',
        'workspace_id' => null,
        'environment' => 'development',
        'connector' => null,
        'connection' => [],
        'destination' => 'temm',
        'target' => [
            'host' => '', 'port' => '5432', 'database' => '',
            'username' => '', 'password' => '',
        ],
        'target_disposable' => true,
    ];

    public int $step = 1;

    public ?int $projectId = null;

    public ?int $sourceId = null;

    public ?int $analysisId = null;

    public ?int $planId = null;

    /** Last connection-test outcome (safe labels only — never credentials). */
    public ?array $testResult = null;

    public ?array $planSummary = null;

    public string $error = '';

    /** 0.6.0 Phase A (§A7): the underlying reason, shown ONLY behind the
     * "Technical details" disclosure in the error banner. */
    public string $errorDetail = '';

    /** §E2 — inline workspace creation, without leaving the wizard. */
    public bool $creatingWorkspace = false;

    public string $newWorkspaceName = '';

    public string $newWorkspaceKind = 'client';

    /** Whether this mount restored a saved draft (shown as a resume note). */
    public bool $resumed = false;

    public static function canAccess(): bool
    {
        return PlatformAccess::current()->canCreateProject();
    }

    public static function getNavigationLabel(): string
    {
        return __('wizard.title');
    }

    public function getHeading(): string
    {
        return __('wizard.title');
    }

    public function getSubheading(): ?string
    {
        return __('wizard.subtitle');
    }

    public function mount(): void
    {
        $this->restoreDraft();

        // Workspace prefill (?workspace=N) — validated against reachability,
        // and never over a draft that already carries a choice.
        $requested = request()->query('workspace');
        if ($requested !== null
            && $this->state['workspace_id'] === null
            && $this->accessibleWorkspaces()->has((int) $requested)) {
            $this->state['workspace_id'] = (int) $requested;
        }
    }

    /** ── Draft persistence (§E3) ────────────────────────────────────── */

    /**
     * Restore the user's saved wizard state. Secrets are NOT part of the
     * draft: a resumed connection step shows an empty password field and a
     * note saying so — the vault never echoes secrets back.
     */
    protected function restoreDraft(): void
    {
        $draft = ProjectWizardDraft::forCurrentUser();
        if ($draft === null) {
            return;
        }

        $state = (array) ($draft->state ?? []);
        // Defensive merge: an old draft must never crash on a changed shape.
        $this->state = array_replace($this->state, $state);
        $this->step = max(1, min(count(self::STEPS), (int) $draft->step));
        $this->projectId = $draft->project_id;
        $this->sourceId = $draft->source_id;
        $this->analysisId = $draft->analysis_id;
        $this->planId = $draft->plan_id;
        $this->testResult = $draft->test_result;
        // The resume note appears whenever the draft actually carries work —
        // typed fields, a later step, or a created project.
        $this->resumed = $draft->step > 1
            || $draft->project_id !== null
            || trim((string) ($this->state['name'] ?? '')) !== '';
    }

    /** Persist safe wizard state. Called on field updates and step changes. */
    public function saveDraft(): void
    {
        ProjectWizardDraft::store($this->step, $this->safeState(), [
            'project_id' => $this->projectId,
            'source_id' => $this->sourceId,
            'analysis_id' => $this->analysisId,
            'plan_id' => $this->planId,
        ], $this->testResult);
    }

    /** Wizard state with every secret removed (vaults are write-once). */
    protected function safeState(): array
    {
        $state = $this->state;
        $secretKeys = ['password'];

        foreach ($this->connectionFields() as $field) {
            if ($field->secret) {
                $secretKeys[] = $field->key;
            }
        }

        foreach ($secretKeys as $key) {
            if (isset($state['connection'][$key])) {
                $state['connection'][$key] = '';
            }
        }
        $state['target']['password'] = '';

        return $state;
    }

    /** Livewire hook: any state.* change keeps the draft in sync. */
    public function updatedState(): void
    {
        $this->saveDraft();
    }

    /** "Start over" — explicitly discard a resumed draft. */
    public function discardDraft(): void
    {
        ProjectWizardDraft::query()->where('user_id', auth()->id())->delete();
        $this->state = [
            'name' => '',
            'workspace_id' => null,
            'environment' => 'development',
            'connector' => null,
            'connection' => [],
            'destination' => 'temm',
            'target' => [
                'host' => '', 'port' => '5432', 'database' => '',
                'username' => '', 'password' => '',
            ],
            'target_disposable' => true,
        ];
        $this->step = 1;
        $this->projectId = null;
        $this->sourceId = null;
        $this->analysisId = null;
        $this->planId = null;
        $this->testResult = null;
        $this->planSummary = null;
        $this->error = '';
        $this->errorDetail = '';
        $this->resumed = false;
    }

    /** ── Inline workspace creation (§E2) ───────────────────────────── */
    public function canCreateWorkspace(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::WORKSPACES_CREATE);
    }

    public function openInlineWorkspaceCreate(): void
    {
        $this->creatingWorkspace = true;
        $this->newWorkspaceName = '';
        $this->newWorkspaceKind = 'client';
    }

    public function cancelInlineWorkspaceCreate(): void
    {
        $this->creatingWorkspace = false;
        $this->newWorkspaceName = '';
    }

    /**
     * Create a workspace from INSIDE the wizard: the user never abandons the
     * flow, and every field they typed stays exactly as it was.
     */
    public function createWorkspaceInline(): void
    {
        // Execution-time re-check: an open form is not permission to act.
        if (! $this->canCreateWorkspace()) {
            abort(403, 'Missing capability: '.Capability::WORKSPACES_CREATE);
        }

        $this->validate([
            'newWorkspaceName' => ['required', 'string', 'max:120'],
        ], [], ['newWorkspaceName' => __('workspaces.workspace_name')]);

        $workspace = Workspace::create([
            'name' => $this->newWorkspaceName,
            'slug' => $this->uniqueWorkspaceSlug($this->newWorkspaceName),
            'kind' => $this->newWorkspaceKind,
            'status' => 'active',
        ]);

        AdminAudit::record('WORKSPACE_CREATED', null, 'workspace', $workspace->id, [
            'name' => $workspace->name, 'origin' => 'new_project_wizard',
        ]);

        $this->state['workspace_id'] = $workspace->id;
        $this->creatingWorkspace = false;
        $this->newWorkspaceName = '';
        $this->saveDraft();

        Notification::make()
            ->title(__('wizard.workspace_created'))
            ->body(__('wizard.workspace_created_and_selected', ['name' => $workspace->name]))
            ->success()
            ->send();
    }

    protected function uniqueWorkspaceSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $candidate = $base;
        $i = 2;

        while (Workspace::query()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }

    /** ── Data helpers ───────────────────────────────────────────────── */

    /** Only workspaces this user may actually reach (tenant isolation). */
    public function accessibleWorkspaces(): Collection
    {
        return Access::for(auth()->user())
            ->accessibleWorkspaces()
            ->sortBy('name')
            ->mapWithKeys(fn (Workspace $w) => [$w->id => $w->name]);
    }

    /**
     * §E4 — connector cards in USEFUL order: mainstream databases first,
     * developer example last. Registry order is fallback for unknown keys.
     */
    public const CARD_ORDER = ['postgres', 'mysql', 'supabase', 'mongodb', 'firebase', 'example-json'];

    public function sourceCards(): array
    {
        $cards = [];

        foreach (ConnectorRegistry::all() as $key => $descriptor) {
            $capabilities = (array) ($descriptor['capabilities'] ?? []);
            $cards[] = [
                'key' => $key,
                'name' => $descriptor['name'],
                // §E4 — one product sentence per card, no phase IDs. The
                // manifest description stays available under Technical
                // details on the same step.
                'description' => __('wizard.source_desc_'.$key),
                'migration' => in_array('database_metadata', $capabilities, true)
                    || in_array('data_extraction', $capabilities, true),
                'live_sync' => in_array('change_capture', $capabilities, true),
                'read_only' => true,
                'selected' => $this->state['connector'] === $key,
                'full_description' => (string) $descriptor['description'],
            ];
        }

        usort($cards, fn ($a, $b) => $this->cardRank($a['key']) <=> $this->cardRank($b['key']));

        return $cards;
    }

    protected function cardRank(string $key): int
    {
        $rank = array_search($key, self::CARD_ORDER, true);

        return $rank === false ? 999 : (int) $rank;
    }

    /**
     * §E4 — declarative credential fields for the selected connector,
     * split into PRIMARY (required/common, canonical order: Host → Port →
     * Database → Username → Password) and ADVANCED (expert fields).
     *
     * @return array{primary: array, advanced: array}
     */
    public function connectionFieldGroups(): array
    {
        $primary = [];
        $advanced = [];

        foreach ($this->connectionFields() as $field) {
            $isCore = in_array($field->key, self::FIELD_ORDER, true);
            $isAdvanced = in_array($field->key, self::ADVANCED_FIELDS, true)
                || (! $isCore && ! $field->required && $field->type !== 'boolean');

            if ($isAdvanced) {
                $advanced[] = $field;
            } else {
                $primary[] = $field;
            }
        }

        $orderBy = array_flip(self::FIELD_ORDER);
        usort($primary, fn ($a, $b) => ($orderBy[$a->key] ?? 99) <=> ($orderBy[$b->key] ?? 99));

        return ['primary' => $primary, 'advanced' => $advanced];
    }

    public function connectionFields(): array
    {
        $connector = $this->connector();

        if ($connector === null) {
            return [];
        }

        return $connector->credentialSchema();
    }

    public function connector(): ?Connector
    {
        try {
            $key = $this->state['connector'];

            return $key ? ConnectorRegistry::instance()->sourceConnector($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function connectorName(): string
    {
        return ConnectorRegistry::label((string) $this->state['connector']) ?? (string) $this->state['connector'];
    }

    public function selectedConnectorSupportsLiveSync(): bool
    {
        $connector = $this->connector();

        return $connector !== null && in_array('change_capture', $connector->definition()->capabilities ?? [], true);
    }

    /** Project record once created (steps 3–5 operate on it). */
    public function project(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    /** AI help deep-link per step (B.9) — navigation only, never auto-edit. */
    public function aiHelpUrl(): string
    {
        $topics = [
            1 => 'How do I choose a workspace and environment for a new project?',
            2 => 'Which source connector should I choose, and why might a connection test fail?',
            3 => 'What is the difference between TEMM-managed infrastructure and an external PostgreSQL target?',
            4 => 'What does the source analysis check, and what do warnings mean?',
            5 => 'What happens during a migration dry run and what is Live Sync?',
        ];

        $topic = $topics[$this->step] ?? 'I need help with a new project.';

        return NexusAi::pageUrl().'?topic='.urlencode($topic);
    }

    /** ── Navigation ─────────────────────────────────────────────────── */
    public function goToStep(int $step): void
    {
        $this->step = max(1, min(count(self::STEPS), $step));
        $this->error = '';
        $this->errorDetail = '';
        $this->saveDraft();
    }

    public function back(): void
    {
        $this->goToStep($this->step - 1);
    }

    /** Continue: validate the CURRENT step server-side, then advance. */
    public function continue(): void
    {
        $this->error = '';
        $this->errorDetail = '';

        try {
            match ($this->step) {
                1 => $this->validateProjectStep(),
                2 => $this->completeSourceStep(),
                3 => $this->validateDestinationStep(),
                4 => $this->goToStep(5),
                default => null,
            };
        } catch (ValidationException $e) {
            // Field errors are already in the Livewire bag — let them through.
            $this->error = __('wizard.continue_disabled_hint');
            throw $e;
        } catch (\Throwable $e) {
            // 0.6.0 Phase A (§A7): the user sees WHAT FAILED and what to do
            // next; the underlying exception moves behind Technical details.
            $this->error = __('foundation.wizard_step_failed_body');
            $this->errorDetail = class_basename($e).': '.$e->getMessage();

            report($e);
        }
    }

    /** ── Step logic ─────────────────────────────────────────────────── */
    protected function validateProjectStep(): void
    {
        $this->validate([
            'state.name' => ['required', 'string', 'max:128'],
            'state.workspace_id' => ['required', 'integer'],
            'state.environment' => ['required', 'in:'.implode(',', self::ENVIRONMENTS)],
        ], [], [
            'state.name' => __('wizard.project_name'),
            'state.workspace_id' => __('wizard.workspace_client'),
            'state.environment' => __('wizard.environment'),
        ]);

        // Tenant isolation: only a workspace the user can reach is accepted.
        if (! $this->accessibleWorkspaces()->has((int) $this->state['workspace_id'])) {
            $this->addError('state.workspace_id', __('common.status_error'));

            return;
        }

        $this->goToStep(2);
    }

    /**
     * §E4 — Source step: connector choice, connection fields and the
     * passing connection test are ONE step. Leaving it persists the project
     * + read-only source (and vaults the secrets).
     */
    protected function completeSourceStep(): void
    {
        $this->validate([
            'state.connector' => ['required', 'string'],
        ], [], [
            'state.connector' => __('wizard.step_source'),
        ]);

        $connector = $this->connector();

        if ($connector === null) {
            $this->addError('state.connector', __('common.status_error'));

            return;
        }

        // Required fields per the connector's declarative schema.
        foreach ($connector->credentialSchema() as $field) {
            if (! $field->required) {
                continue;
            }
            $value = $this->state['connection'][$field->key] ?? '';
            if (trim((string) $value) === '') {
                $this->addError('state.connection.'.$field->key, __('common.required'));
                $this->error = __('wizard.continue_disabled_hint');

                return;
            }
        }

        if ($this->testResult === null || ! ($this->testResult['ok'] ?? false)) {
            $this->error = __('wizard.test_first');

            return;
        }

        $this->persistProjectAndSource();
        $this->goToStep(3);
    }

    protected function validateDestinationStep(): void
    {
        try {
            if (($this->state['destination'] ?? 'temm') === 'external') {
                $this->validate([
                    'state.target.host' => ['required', 'string', 'max:255'],
                    'state.target.port' => ['required', 'integer', 'min:1', 'max:65535'],
                    'state.target.database' => ['required', 'string', 'max:128'],
                    'state.target.username' => ['required', 'string', 'max:128'],
                    'state.target.password' => ['required', 'string', 'max:8000'],
                ], [], [
                    'state.target.host' => __('wizard.target_host'),
                    'state.target.database' => __('wizard.target_database'),
                ]);
            }

            $project = $this->project();
            abort_unless($project, 422, __('wizard.start_blocked_no_project'));
            $environment = EnvironmentService::defaultFor($project);
            $provisioner = app(ProjectDatabaseProvisioner::class);
            if (($this->state['destination'] ?? 'temm') === 'external') {
                $provisioner->configure($project, $environment, $this->state['target'] + ['sslmode' => 'prefer'], 'external');
            } else {
                $provisioner->provision($project, $environment);
            }

            $this->goToStep(4);
        } finally {
            $this->state['target']['password'] = '';
        }
    }

    /**
     * §E5 — connection test. The outcome is CLASSIFIED deterministically so
     * the UI can speak plainly: success, credentials refused, unreachable,
     * invalid configuration — or a private-network source, whose recovery
     * belongs to an administrator, never to an env-var instruction.
     */
    public function testConnection(): void
    {
        $connector = $this->connector();

        if ($connector === null) {
            return;
        }

        $this->error = '';

        try {
            if (! method_exists($connector, 'testConnection')) {
                $this->testResult = ['ok' => false, 'available' => false, 'kind' => 'unsupported'];

                return;
            }

            $secretKeys = [];
            $values = [];

            foreach ($connector->credentialSchema() as $field) {
                $value = $this->state['connection'][$field->key] ?? $field->default;

                if ($value === null || $value === '') {
                    continue;
                }

                $values[$field->key] = $value;

                if ($field->secret) {
                    $secretKeys[] = $field->key;
                }
            }

            $result = $connector->testConnection(ConnectorCredentials::fromArray($values, $secretKeys));

            $this->testResult = [
                'ok' => $result->isPass(),
                'available' => true,
                'kind' => $result->isPass()
                    ? 'success'
                    : $this->classifyTestFailure($result->result, $result->detail),
                'result' => $result->result,
                'detail' => $result->detail,
                'metadata' => $result->metadata,
            ];
        } catch (HttpExceptionInterface $e) {
            // The SSRF network guard refused this target (422). Security is
            // preserved — the UX explains WHO can change it.
            $this->testResult = [
                'ok' => false,
                'available' => true,
                'kind' => str_contains($e->getMessage(), 'private network') || str_contains($e->getMessage(), 'SSRF')
                    ? 'private_network'
                    : 'network',
                'result' => ConnectorTestResult::NETWORK_ERROR,
                'detail' => mb_substr($e->getMessage(), 0, 300),
                'metadata' => [],
            ];
        } catch (\Throwable) {
            $this->testResult = [
                'ok' => false,
                'available' => true,
                'kind' => 'network',
                'result' => ConnectorTestResult::NETWORK_ERROR,
                'detail' => '',
                'metadata' => [],
            ];
        }

        $this->saveDraft();
    }

    /** Map a connector test failure to a stable presentation kind. */
    protected function classifyTestFailure(string $result, string $detail): string
    {
        // The SSRF guard's refusal arrives as PROVIDER_ERROR (HttpException is
        // RuntimeException-shaped) — it must classify as the private-network
        // recovery path, never as a generic provider fault (§E5).
        if (str_contains($detail, 'private network') || str_contains($detail, 'SSRF')) {
            return 'private_network';
        }

        return match ($result) {
            ConnectorTestResult::INVALID_CREDENTIAL => 'auth',
            ConnectorTestResult::INVALID_CONFIGURATION => 'invalid',
            ConnectorTestResult::PROVIDER_ERROR => 'provider',
            ConnectorTestResult::NETWORK_ERROR => 'network',
            default => 'network',
        };
    }

    /** True when the failure is the admin-gated private-network case (§E5). */
    public function testFailedOnPrivateNetwork(): bool
    {
        return ($this->testResult['kind'] ?? null) === 'private_network';
    }

    /** §E5 — "Open system settings" renders only for users who have it. */
    public function canOpenSystemSettings(): bool
    {
        return SettingsHub::canAccess();
    }

    /** ── Analyze (§E7): real staged progress, tick by tick ──────────── */
    public function analysis(): ?MigrationAnalysis
    {
        return $this->analysisId !== null ? MigrationAnalysis::find($this->analysisId) : null;
    }

    /** Start (or re-start) the read-only source analysis. */
    public function startAnalysis(): void
    {
        $source = $this->sourceId !== null ? MigrationSource::find($this->sourceId) : null;

        if ($source === null) {
            $this->error = __('foundation.wizard_step_failed_body');
            $this->errorDetail = 'missing migration source';

            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');

        $service = new MigrationCenterService;
        $analysis = $service->beginAnalysis($source);
        $this->analysisId = $analysis->id;
        $this->planId = null;
        $this->planSummary = null;
        $this->error = '';
        $this->errorDetail = '';
        $this->saveDraft();

        $this->tickAnalysis();
    }

    /**
     * One REAL pipeline stage per call (§E7). The view polls this while the
     * analysis is running; each tick does actual work and records it.
     */
    public function tickAnalysis(): void
    {
        $analysis = $this->analysis();

        if ($analysis === null || $analysis->status !== 'running') {
            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');

        $service = new MigrationCenterService;
        $analysis = $service->advanceAnalysis($analysis);

        if ($analysis->status === 'failed') {
            // §E8 — blocking errors speak plainly; raw detail stays disclosed.
            $this->error = __('wizard.analyze_failed_body');
            $this->errorDetail = $this->analysisTechnicalDetail($analysis);

            return;
        }

        if ($analysis->status === 'completed') {
            $this->error = '';

            // The review plan is generated here so Step 5 can show readiness.
            if ($this->planId === null) {
                $plan = $service->generatePlan($analysis);
                $this->planId = $plan->id;
                $this->planSummary = ['items' => $plan->items()->count()];
                $this->saveDraft();
            }
        }
    }

    /** §E7 — cancel-safe where the architecture supports it: any tick. */
    public function cancelAnalysis(): void
    {
        $analysis = $this->analysis();

        if ($analysis === null) {
            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');
        (new MigrationCenterService)->cancelAnalysis($analysis);
        $this->saveDraft();
    }

    /** Analysis progress for the view: real stages from real telemetry. */
    public function analysisProgress(): array
    {
        $analysis = $this->analysis();
        if ($analysis === null) {
            return ['running' => false, 'stages' => [], 'current' => null, 'status' => null];
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

    /**
     * §E9 — the analysis summary, in product language. Warnings are items
     * the user can act on, never raw SQLSTATE.
     */
    public function analysisSummary(): array
    {
        $analysis = $this->analysis();
        if ($analysis === null || $analysis->status !== 'completed') {
            return [];
        }

        $counts = (array) ($analysis->counts ?? []);

        return [
            'tables' => (int) ($counts['tables'] ?? 0),
            'views' => (int) ($counts['views'] ?? 0),
            'auth' => (int) ($counts['auth'] ?? 0),
            'storage' => (int) ($counts['storage'] ?? 0),
            'functions' => (int) ($counts['functions'] ?? 0),
            'triggers' => (int) ($counts['triggers'] ?? 0),
            'policies' => (int) ($counts['policies'] ?? 0),
            'realtime' => (int) ($counts['realtime'] ?? 0),
            'warnings' => $this->analysisWarnings($analysis),
        ];
    }

    /**
     * §E8 — warnings in plain language, through THE shared classifier
     * mapping (the Migration journey renders the identical list).
     *
     * @return list<array{check: string, title: string, detail: string}>
     */
    public function analysisWarnings(?MigrationAnalysis $analysis = null): array
    {
        $analysis ??= $this->analysis();

        return $analysis === null
            ? []
            : AnalysisOutcomeClassifier::present((array) ($analysis->warnings ?? []));
    }

    /** Raw diagnostics for the Technical details disclosure. */
    public function analysisTechnicalDetail(?MigrationAnalysis $analysis = null): string
    {
        $analysis ??= $this->analysis();
        if ($analysis === null) {
            return '';
        }

        if ($analysis->status === 'failed') {
            $errors = (array) ($analysis->errors ?? []);

            return trim(implode(' — ', array_filter([
                'kind: '.($errors['kind'] ?? 'unknown'),
                'SQLSTATE: '.($errors['sqlstate'] ?? '—'),
                (string) ($errors['message'] ?? ''),
            ])));
        }

        return AnalysisOutcomeClassifier::technicalFor($analysis);
    }

    /** ── Review (§E10): deterministic readiness ─────────────────────── */

    /**
     * §E10 — readiness is a DERIVED FACT, never a badge. Ready requires a
     * completed analysis, at least one discovered table, and a generated
     * plan with transfer steps. A green "Ready" over Tables = 0 is
     * impossible here.
     *
     * @return array{ready: bool, reason: ?string, tables: int, analysis_done: bool, plan_items: ?int}
     */
    public function reviewReadiness(): array
    {
        $analysis = $this->analysis();
        $analysisDone = $analysis !== null && $analysis->status === 'completed';
        $tables = $analysisDone ? (int) (($analysis->counts['tables'] ?? 0)) : 0;
        $planItems = $this->planId !== null ? (MigrationPlan::find($this->planId)?->items()->count()) : null;

        $reason = match (true) {
            ! $analysisDone => __('wizard.review_reason_no_analysis'),
            $tables === 0 => __('wizard.review_reason_no_tables'),
            $planItems === null || $planItems === 0 => __('wizard.review_reason_no_plan'),
            default => null,
        };

        return [
            'ready' => $reason === null,
            'reason' => $reason,
            'tables' => $tables,
            'analysis_done' => $analysisDone,
            'plan_items' => $planItems,
        ];
    }

    /** §E8 — compatibility blockers for the review list (plain language). */
    public function reviewBlockers(): array
    {
        $analysis = $this->analysis();
        if ($analysis === null || $analysis->status !== 'completed') {
            return [];
        }

        $counts = [];
        foreach ($analysis->items()->get(['kind', 'compatibility']) as $item) {
            if ($item->compatibility === CompatibilityClassifier::BLOCKED) {
                $counts[$item->kind] = ($counts[$item->kind] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counts as $kind => $n) {
            $out[] = [
                'title' => trans_choice('wizard.review_blocked_items', $n, ['count' => $n, 'kind' => __('wizard.analyze_counts_'.$kind)]),
            ];
        }

        return $out;
    }

    /** ── Start Migration (§E11): fail with a reason, never a bare Error ── */
    public function startMigration(): void
    {
        $project = $this->project();
        $readiness = $this->reviewReadiness();

        // §E11 — every refusal names WHAT is missing and HOW to fix it.
        if ($project === null) {
            $this->error = __('wizard.start_blocked_no_project');
            $this->errorDetail = __('wizard.start_blocked_no_project_detail');

            return;
        }

        if (! $readiness['analysis_done'] || $readiness['plan_items'] === null) {
            $this->error = __('wizard.start_blocked_no_analysis');

            return;
        }

        if ($readiness['tables'] === 0) {
            $this->error = __('wizard.start_blocked_no_tables');

            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');

        try {
            $plan = MigrationPlan::findOrFail($this->planId);

            $target = $this->destinationTarget($project);

            $manager = new MigrationRunManager;
            $run = $manager->start($plan, [
                'mode' => 'dry_run',
                'target' => $target,
                'target_disposable' => (bool) $this->state['target_disposable'],
                'target_environment_type' => EnvironmentContext::active($project)->type,
            ]);
            $manager->execute($run);

            AdminAudit::record('WIZARD_MIGRATION_STARTED', $project, 'migration_run', $run->id, [
                'run_id' => $run->run_id, 'mode' => 'dry_run',
            ]);

            Notification::make()
                ->title(__('wizard.migration_started', ['status' => $run->status]))
                ->success()
                ->send();

            // The journey continues on the project's Migration tab, Sync stage.
            $this->redirect(ProjectResource::getUrl('migration', [
                'record' => $project, 'stage' => 'sync',
            ]));
        } catch (\Throwable $e) {
            // 0.6.0 Phase A (§A7): never a bare "Error" — the run failed for a
            // reason the user can act on; the exception is one click away.
            $this->error = __('wizard.start_failed_body');
            $this->errorDetail = class_basename($e).': '.$e->getMessage();

            report($e);
        }
    }

    /** ── Persistence ────────────────────────────────────────────────── */

    /** Creates (or updates) the project + read-only source; vaults secrets. */
    protected function persistProjectAndSource(): void
    {
        $project = $this->project();

        if ($project === null) {
            $project = $this->createProject();
            $this->projectId = $project->id;
        } else {
            $project->update(['name' => $this->state['name']]);
        }

        $connector = $this->connector();
        $connector ??= ConnectorRegistry::instance()->sourceConnector((string) $this->state['connector']);

        $configuration = ['display_name' => $this->state['name'].' source'];
        foreach ($connector->credentialSchema() as $field) {
            if ($field->secret) {
                continue;
            }
            $value = $this->state['connection'][$field->key] ?? null;
            if ($value !== null && $value !== '') {
                $configuration[$field->key] = $value;
            }
        }

        $source = $this->sourceId !== null ? MigrationSource::find($this->sourceId) : null;

        if ($source === null) {
            $source = $connector->createSourceProfile(
                $project,
                [],
                $configuration,
                EnvironmentService::defaultFor($project)->id,
            );
            $this->sourceId = $source->id;
        } else {
            $source->update(['connection' => $configuration]);
        }

        // Secrets → project vault, referenced by name (never stored inline).
        $connection = $source->connection ?? [];
        $secretRefs = $source->secret_refs ?? [];
        foreach ($connector->credentialSchema() as $field) {
            $value = $this->state['connection'][$field->key] ?? null;
            if ($value === null || $value === '' || ! $field->secret) {
                continue;
            }
            $secretName = 'SOURCE_'.strtoupper($field->key);
            SecretVaultService::createSecret($project, $secretName, (string) $value, ['category' => 'database']);
            $secretRefs[$field->key] = $secretName;
            unset($connection[$field->key]);
        }
        $source->update(['connection' => $connection, 'secret_refs' => $secretRefs]);

        // §E14 — the Connect stage shows the last successful connection test.
        $source->update(['last_tested_at' => now()]);

        AdminAudit::record('WIZARD_SOURCE_CONNECTED', $project, 'migration_source', $source->id, [
            'connector' => $this->state['connector'],
        ]);

        Notification::make()->title(__('wizard.project_created', ['name' => $this->state['name']]))->success()->send();

        $this->saveDraft();
    }

    protected function createProject(): Project
    {
        // The page gate re-checked at the mutation point (never trust the flow).
        abort_unless(PlatformAccess::current()->canCreateProject(), 403);

        $base = Str::slug((string) $this->state['name']) ?: 'project';
        $slug = mb_substr($base, 0, 40);
        $attempt = 0;

        while (Project::where('slug', $slug)->exists()) {
            $attempt++;
            $slug = mb_substr($base, 0, 36).'-'.$attempt;
        }

        $project = Project::create([
            'workspace_id' => (int) $this->state['workspace_id'],
            'name' => (string) $this->state['name'],
            'slug' => $slug,
            'status' => 'active',
            'environment' => (string) $this->state['environment'],
            'db_name' => str_replace('-', '_', $slug).'_db',
            'redis_prefix' => str_replace('-', '', $slug).':',
            'storage_disk' => 'local',
            'timezone' => 'UTC',
            'locale' => app()->getLocale() === 'ar' ? 'en' : app()->getLocale(),
            'notes' => 'Created via the New Project wizard.',
        ]);

        EnvironmentService::ensureDefaults($project);
        $selected = $project->environments()->where('type', $this->state['environment'])->first();
        $project->environments()->update(['is_default' => false]);
        // The bulk update bypasses this model's original attributes: refresh before setting true.
        $selected->refresh()->update(['is_default' => true, 'status' => 'active']);

        return $project;
    }

    /** Target connection for the run manager (dry-run display / real runs). */
    protected function destinationTarget(Project $project): array
    {
        if (($this->state['destination'] ?? 'temm') !== 'external') {
            return ProjectConnectionManager::migrationTarget($project, false);
        }

        $environment = EnvironmentContext::active($project);
        if ($environment->database_connection) {
            return ProjectConnectionManager::migrationTarget($project, true);
        }

        $target = [
            'host' => $this->state['target']['host'] ?? null,
            'port' => $this->state['target']['port'] ?? null,
            'database' => $this->state['target']['database'] ?? null,
            'username' => $this->state['target']['username'] ?? null,
            'secret_refs' => [],
        ];

        $password = $this->state['target']['password'] ?? '';
        if ($password !== '' && $project !== null) {
            SecretVaultService::createSecret($project, 'TARGET_PASSWORD', (string) $password, ['category' => 'database']);
            $target['secret_refs'] = ['password' => 'TARGET_PASSWORD'];
        }
        $this->state['target']['password'] = '';

        return array_filter($target);
    }
}
