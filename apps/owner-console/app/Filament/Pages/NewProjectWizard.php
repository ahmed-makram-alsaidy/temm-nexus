<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Access\Access;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentService;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Services\ControlPlane\SecretVaultService;
use App\Services\ControlPlane\Connectors\Contracts\Connector;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * 0.4.0-rc.5 (Phase 41, Part B) — the New Project wizard.
 *
 * A first-time user connects a project in six guided steps:
 *
 *   1. Project      — name, workspace/client, environment
 *   2. Source       — visual provider cards (migration / Live Sync support)
 *   3. Connection   — only the fields the provider needs + Test Connection
 *   4. Destination  — TEMM-managed (default) or an external PostgreSQL target
 *   5. Analyze      — read-only source analysis in product language
 *   6. Review       — plain-language readiness + Start Migration (dry run)
 *
 * Engine internals (TargetAdapter, TransformPipeline, checkpoints, CDC
 * vocabulary) stay under the hood or in the Migration Center. Secrets go
 * straight to the project vault and are never echoed back to the browser.
 *
 * Authorization: page requires the user to be able to create a project
 * SOMEWHERE; the chosen workspace is re-verified against the user's
 * accessible workspaces on every continue, and mutating actions re-check
 * `migrations.manage` like the rest of the control plane.
 */
class NewProjectWizard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $slug = 'new-project';

    protected string $view = 'filament.pages.new-project-wizard';

    // Reached from Home / Projects / Workspace pages, not from the sidebar —
    // the wizard is an ACTION, not a destination (mirrors WorkspaceDetail).
    protected static bool $shouldRegisterNavigation = false;

    public const STEPS = ['project', 'source', 'connection', 'destination', 'analyze', 'review'];

    public const ENVIRONMENTS = ['production', 'staging', 'development'];

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

    /** Analysis summary in product language. */
    public ?array $analysisSummary = null;

    public ?array $planSummary = null;

    public string $error = '';

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
        // Workspace prefill (?workspace=N) — validated against reachability.
        $requested = request()->query('workspace');
        if ($requested !== null && $this->accessibleWorkspaces()->has((int) $requested)) {
            $this->state['workspace_id'] = (int) $requested;
        }
    }

    /** ── Data helpers ───────────────────────────────────────────────── */

    /** Only workspaces this user may actually reach (tenant isolation). */
    public function accessibleWorkspaces(): \Illuminate\Support\Collection
    {
        return Access::for(auth()->user())
            ->accessibleWorkspaces()
            ->sortBy('name')
            ->mapWithKeys(fn (Workspace $w) => [$w->id => $w->name]);
    }

    /** Source provider cards from the connector registry (B.3). */
    public function sourceCards(): array
    {
        $cards = [];

        foreach (ConnectorRegistry::all() as $key => $descriptor) {
            $capabilities = (array) ($descriptor['capabilities'] ?? []);
            $cards[] = [
                'key' => $key,
                'name' => $descriptor['name'],
                'description' => $descriptor['description'],
                'migration' => in_array('database_metadata', $capabilities, true)
                    || in_array('data_extraction', $capabilities, true),
                'live_sync' => in_array('change_capture', $capabilities, true),
                'read_only' => true,
                'selected' => $this->state['connector'] === $key,
            ];
        }

        return $cards;
    }

    /** Declarative credential fields for the selected connector (B.4). */
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

    /** Project record once created (steps 4–6 operate on it). */
    public function project(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    /** AI help deep-link per step (B.9) — navigation only, never auto-edit. */
    public function aiHelpUrl(): string
    {
        $topics = [
            1 => 'How do I choose a workspace and environment for a new project?',
            2 => 'Which source connector should I choose for my migration?',
            3 => 'Which connection fields are required, and why might a connection test fail?',
            4 => 'What is the difference between TEMM-managed infrastructure and an external PostgreSQL target?',
            5 => 'What does the source analysis check, and what do warnings mean?',
            6 => 'What happens during a migration dry run and what is Live Sync?',
        ];

        $topic = $topics[$this->step] ?? 'I need help with a new project.';

        return \App\Filament\Support\NexusAi::pageUrl().'?topic='.urlencode($topic);
    }

    /** ── Navigation ─────────────────────────────────────────────────── */

    public function goToStep(int $step): void
    {
        $this->step = max(1, min(count(self::STEPS), $step));
        $this->error = '';
    }

    public function back(): void
    {
        $this->goToStep($this->step - 1);
    }

    /** Continue: validate the CURRENT step server-side, then advance. */
    public function continue(): void
    {
        $this->error = '';

        try {
            match ($this->step) {
                1 => $this->validateProjectStep(),
                2 => $this->validateSourceStep(),
                3 => $this->completeConnectionStep(),
                4 => $this->validateDestinationStep(),
                5 => $this->goToStep(6),
                default => null,
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Field errors are already in the Livewire bag — let them through.
            $this->error = __('wizard.continue_disabled_hint');
            throw $e;
        } catch (\Throwable $e) {
            // Outside production the class+message is shown so a broken step
            // is diagnosable; production shows a safe generic error only.
            $this->error = app()->isProduction()
                ? __('common.status_error')
                : class_basename($e).': '.$e->getMessage();

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

    protected function validateSourceStep(): void
    {
        $this->validate([
            'state.connector' => ['required', 'string'],
        ], [], [
            'state.connector' => __('wizard.step_source'),
        ]);

        try {
            ConnectorRegistry::instance()->sourceConnector((string) $this->state['connector']);
        } catch (\Throwable) {
            $this->addError('state.connector', __('common.status_error'));

            return;
        }

        $this->goToStep(3);
    }

    /** Step 3 → 4: test must pass (or be unavailable), then persist. */
    protected function completeConnectionStep(): void
    {
        $connector = $this->connector();

        if ($connector === null) {
            $this->error = __('common.status_error');

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
        $this->goToStep(4);
    }

    protected function validateDestinationStep(): void
    {
        if (($this->state['destination'] ?? 'temm') === 'external') {
            $this->validate([
                'state.target.host' => ['required', 'string', 'max:255'],
                'state.target.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'state.target.database' => ['required', 'string', 'max:128'],
                'state.target.username' => ['nullable', 'string', 'max:128'],
            ], [], [
                'state.target.host' => __('wizard.target_host'),
                'state.target.database' => __('wizard.target_database'),
            ]);
        }

        $this->goToStep(5);
    }

    /** B.4/B.5 — Test Connection against the selected provider. */
    public function testConnection(): void
    {
        $connector = $this->connector();

        if ($connector === null) {
            return;
        }

        $this->error = '';

        try {
            if (! method_exists($connector, 'testConnection')) {
                $this->testResult = ['ok' => false, 'available' => false];

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
                'result' => $result->result,
                'detail' => $result->detail,
                'metadata' => $result->metadata,
            ];
        } catch (\Throwable) {
            $this->testResult = [
                'ok' => false,
                'available' => true,
                'result' => ConnectorTestResult::NETWORK_ERROR,
                'detail' => '',
                'metadata' => [],
            ];
        }
    }

    /** B.7 — read-only source analysis, summarized in product language. */
    public function analyze(): void
    {
        $source = $this->sourceId !== null ? \App\Models\MigrationSource::find($this->sourceId) : null;

        if ($source === null) {
            $this->error = __('common.status_error');

            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');

        try {
            $service = new MigrationCenterService;
            $analysis = $service->analyze($source);

            if ($analysis->status !== 'completed') {
                $this->error = __('wizard.analyze_failed', ['reason' => $analysis->errors['message'] ?? '']);

                return;
            }

            $service->classify($analysis);

            $counts = (array) ($analysis->counts ?? []);
            $this->analysisSummary = [
                'tables' => (int) ($counts['tables'] ?? 0),
                'views' => (int) ($counts['views'] ?? 0),
                'auth' => (int) ($counts['auth'] ?? 0),
                'storage' => (int) ($counts['storage'] ?? 0),
                'functions' => (int) ($counts['functions'] ?? 0),
                'triggers' => (int) ($counts['triggers'] ?? 0),
                'policies' => (int) ($counts['policies'] ?? 0),
                'realtime' => (int) ($counts['realtime'] ?? 0),
            ];

            // The review plan is generated here so Step 6 can show readiness.
            $plan = $service->generatePlan($analysis);
            $this->planId = $plan->id;
            $this->planSummary = ['items' => $plan->items()->count()];
            $this->analysisId = $analysis->id;
            $this->error = '';
        } catch (\Throwable $e) {
            $this->error = __('wizard.analyze_failed', ['reason' => __('common.status_unknown')]);

            report($e);
        }
    }

    /** B.8 — Start Migration (dry run first: no writes until a real run). */
    public function startMigration(): void
    {
        $project = $this->project();

        if ($project === null || $this->planId === null) {
            $this->error = __('common.status_error');

            return;
        }

        CpAccess::require(auth()->user(), 'migrations.manage');

        $plan = \App\Models\MigrationPlan::findOrFail($this->planId);

        $target = $this->destinationTarget($project);

        $manager = new MigrationRunManager;
        $run = $manager->start($plan, [
            'mode' => 'dry_run',
            'target' => $target,
            'target_disposable' => (bool) $this->state['target_disposable'],
            'target_environment_type' => 'development',
        ]);
        $manager->execute($run);

        AdminAudit::record('WIZARD_MIGRATION_STARTED', $project, 'migration_run', $run->id, [
            'run_id' => $run->run_id, 'mode' => 'dry_run',
        ]);

        Notification::make()
            ->title(__('wizard.migration_started', ['status' => $run->status]))
            ->success()
            ->send();

        $this->redirect(\App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $project]));
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

        $source = $this->sourceId !== null ? \App\Models\MigrationSource::find($this->sourceId) : null;

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

        AdminAudit::record('WIZARD_SOURCE_CONNECTED', $project, 'migration_source', $source->id, [
            'connector' => $this->state['connector'],
        ]);

        Notification::make()->title(__('wizard.project_created', ['name' => $this->state['name']]))->success()->send();
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

        return $project;
    }

    /** Target connection for the run manager (dry-run display / real runs). */
    protected function destinationTarget(Project $project): array
    {
        if (($this->state['destination'] ?? 'temm') !== 'external') {
            // TEMM-managed: the platform provisions the target; nothing to ask.
            return array_filter([
                'host' => $project->db_host ?: null,
                'port' => $project->db_port ?: null,
                'database' => $project->db_name ?: null,
            ]);
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
