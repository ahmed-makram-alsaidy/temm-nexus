<?php

namespace App\Filament\Pages;

use App\Models\OnboardingSession;
use App\Models\Project;
use App\Services\ControlPlane\OnboardingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Phase 24B — Project Onboarding Wizard. CREATE NEW PROJECT or IMPORT
 * EXISTING PROJECT (connector-driven, read-only). Phase 27G/27Q: connector
 * selection is rendered from the Connector Registry; provider-specific
 * import steps are driven by each connector's declared import flow — the
 * wizard core never hardwires a provider. State persists in the database so
 * the operator can leave and resume. Nothing is created until final
 * confirmation; the import flow hands off to the Migration Center.
 */
class OnboardingWizard extends Page
{
    public ?OnboardingSession $session = null;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $slug = 'onboarding';

    protected static ?int $navigationSort = 1;

    public function mount(): void
    {
        if (request()->query('start')) {
            $this->session = OnboardingService::start(auth()->id(), request()->query('start') === 'import' ? 'import' : 'create');
        } else {
            $this->session = OnboardingService::resume(auth()->id());
        }
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string
    {
        return __('labels.onboarding_wizard');
    }

    protected function steps(): array
    {
        return $this->session?->flow === 'import' ? OnboardingService::IMPORT_STEPS : OnboardingService::CREATE_STEPS;
    }

    public function content(Schema $schema): Schema
    {
        $session = $this->session;
        if (! $session) {
            return $schema->components([
                Section::make('Start onboarding')->schema([Html::make(
                    '<p style="font-size:.85rem">Choose a flow:</p>'
                    .'<p><a class="cp-btn" href="'.self::getUrl(['start' => 'create']).'">Create new project</a> &nbsp; '
                    .'<a class="cp-btn" href="'.self::getUrl(['start' => 'import']).'">Import existing project (via connector)</a></p>'
                )])->compact(),
            ]);
        }

        $stepNames = $this->steps();
        $current = $stepNames[$session->current_step] ?? 'finish';
        $state = $session->state ?? [];

        $progress = '<ol style="display:flex;gap:.75rem;list-style:none;padding:0;font-size:.75rem;flex-wrap:wrap">';
        foreach ($stepNames as $i => $name) {
            $done = $i < $session->current_step;
            $active = $i === $session->current_step;
            $progress .= '<li style="'.($active ? 'color:var(--cp-accent-strong);font-weight:600' : ($done ? 'color:var(--cp-success)' : 'color:var(--cp-text-faint)')).'">'
                .($done ? '✓ ' : '').($i + 1).'. '.e($name).'</li>';
        }
        $progress .= '</ol>';

        $summary = '<p style="font-size:.75rem;color:var(--cp-text-dim)">Saved so far: '
            .e(collect($state)->only(['name', 'slug', 'source_type', 'source_ref'])->filter()->implode(', ') ?: 'nothing yet').'</p>';

        return $schema->components([
            Section::make('Wizard — '.($session->flow === 'import' ? 'Import existing project' : 'Create new project').' (step '
                .($session->current_step + 1).'/'.count($stepNames).': '.e($current).')')
                ->schema([Html::make($progress.$summary)])->compact(),
            Section::make('Step data')->schema([$this->stepForm($current, $state)])->compact(),
        ]);
    }

    /** Per-step fields. Kept explicit and small; state persists per step. */
    protected function stepForm(string $step, array $state): Html
    {
        $field = function (string $key, ?string $value) {
            return $value ?? '';
        };

        return Html::make('<p style="font-size:.8rem;color:var(--cp-text-dim)">Fill step "'.$step.'" using the action buttons in the header '
            .'(each step is a form). Current values: <code>'.e(json_encode($state, JSON_UNESCAPED_UNICODE)).'</code></p>');
    }

    protected function getHeaderActions(): array
    {
        if (! $this->session) {
            return [];
        }
        $flow = $this->session->flow;
        $state = $this->session->state ?? [];

        $actions = [];

        if ($flow === 'create') {
            $actions[] = Action::make('identity_step')->label('1. Identity')->schema([
                TextInput::make('name')->required()->default($state['name'] ?? ''),
                TextInput::make('slug')->helperText(__('labels.optional_derived_from_name')),
                TextInput::make('api_domain')->placeholder('api.myapp.test'),
            ])->action(fn (array $data) => $this->saveStep(['name' => $data['name'], 'slug' => $data['slug'] ?: null, 'api_domain' => $data['api_domain'] ?: null]));
            $actions[] = Action::make('platform_steps')->label('2–7. Platform')->schema([
                Select::make('environment')->options(['development' => 'development', 'staging' => 'staging'])->default($state['environment'] ?? 'development'),
                TextInput::make('db_name')->default($state['db_name'] ?? ''),
                TextInput::make('redis_prefix')->default($state['redis_prefix'] ?? ''),
                Toggle::make('auth_enable')->default(true)->dehydrated(false),
                Toggle::make('storage_enable')->default(true)->dehydrated(false),
                Toggle::make('realtime_enable')->default(true)->dehydrated(false),
            ])->action(fn (array $data) => $this->saveStep(array_filter([
                'environment' => $data['environment'],
                'db_name' => $data['db_name'] ?: null,
                'redis_prefix' => $data['redis_prefix'] ?: null,
            ])));
            $actions[] = Action::make('secrets_step')->label('8. Secrets')->schema([
                TextInput::make('secret_name')->placeholder('STRIPE_SECRET')->helperText(__('labels.one_optional_starter_secret')),
                TextInput::make('secret_value')->password()->revealable(),
            ])->action(function (array $data) use ($state) {
                $secrets = $state['secrets'] ?? [];
                if (! empty($data['secret_name']) && ! empty($data['secret_value'])) {
                    $secrets[] = ['name' => $data['secret_name'], 'value' => $data['secret_value'], 'category' => 'application'];
                }
                $this->saveStep(['secrets' => $secrets]);
            });
            $actions[] = Action::make('sdk_step')->label('9. SDK/client')->schema([
                Select::make('primary_client')->options([
                    'flutter' => 'Flutter / Dart', 'javascript' => 'JavaScript / TypeScript', 'react' => 'React / Next', 'php' => 'PHP', 'none' => 'none yet',
                ])->default($state['primary_client'] ?? 'none'),
            ])->action(fn (array $data) => $this->saveStep(['primary_client' => $data['primary_client']]));
        } else {
            // ── Phase 27G — connector-driven import flow ────────────────
            // Connector selection and credentials come from the registry;
            // the wizard interprets each connector's DECLARED import flow
            // and never hardwires a provider (27G.1/27D.2).
            $registry = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance();
            $descriptors = $registry->all();
            $chosenKey = $state['source_type'] ?? null;
            $chosen = null;
            if ($chosenKey) {
                try {
                    $chosen = $registry->sourceConnector((string) $chosenKey);
                } catch (\Throwable) {
                    $chosen = null;
                }
            }
            $isAccountFlow = $chosen instanceof \App\Services\ControlPlane\Connectors\Contracts\AccountDiscoveryConnector;
            $isCredentialsFlow = $chosen !== null && $chosen->manifest()->importFlow() === \App\Services\ControlPlane\Connectors\ConnectorManifest::FLOW_CREDENTIALS_FORM;

            $actions[] = Action::make('identity_step')->label('1. Identity')->schema([
                TextInput::make('name')->required()->default($state['name'] ?? ''),
                TextInput::make('slug')->helperText(__('labels.optional_derived_from_name')),
            ])->action(fn (array $data) => $this->saveStep(['name' => $data['name'], 'slug' => $data['slug'] ?: null]));

            // 27Q — connector cards populated from the registry.
            $connectorOptions = collect($descriptors)
                ->mapWithKeys(fn ($d, $k) => [$k => $d['name'].' (v'.$d['version'].') — '.$d['description']])->all();
            $actions[] = Action::make('choose_source')->label('2. Choose source connector')->icon('heroicon-o-square-3-stack-3d')
                ->schema([
                    Select::make('source_type')->label(__('labels.available_connectors'))->options($connectorOptions)->required()->native(false)
                        ->helperText($descriptors === [] ? 'No connectors registered.' : count($descriptors).' connector(s) registered — first-party, read-only.'),
                ])
                ->action(function (array $data) {
                    $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->sourceConnector($data['source_type']);
                    $definition = $connector->definition();
                    $this->saveStep(['source_type' => $data['source_type']]);
                    Notification::make()->title($definition->name.' selected — trust: '.$definition->trust.', capabilities: '.count($definition->capabilities))->success()->send();
                });

            // ── Account-discovery flow (declared import_flow) ───────────
            $connections = $chosen instanceof \App\Services\ControlPlane\Connectors\Contracts\AccountDiscoveryConnector
                ? \App\Models\ExternalAccountConnection::where('provider', $chosenKey)->where('status', 'connected')
                    ->orderByDesc('id')->pluck('display_name', 'id')->all()
                : [];
            $actions[] = Action::make('connect_account')->label('3. Connect account')->icon('heroicon-o-cloud')
                ->visible($isAccountFlow)
                ->schema(array_merge([
                    Select::make('connection_id')->label(__('labels.existing_connection'))->options($connections)->native(false)
                        ->helperText($connections === [] ? 'No connected account yet — connect one below.' : 'Or connect a new account below.'),
                    TextInput::make('new_display_name')->label(__('labels.new_connection_name')),
                    TextInput::make('new_secret')->password()->revealable()->label($chosen?->definition()->credentialField('pat')?->label ?? 'Account token')
                        ->helperText(__('labels.encrypted_at_rest_never_shown_again_used')),
                ]))
                ->action(function (array $data) use ($chosen) {
                    if (! empty($data['new_secret']) && ! empty($data['new_display_name'])) {
                        $connection = $chosen->connectAccount(auth()->user(), $data['new_display_name'], $data['new_secret']);
                        $test = $chosen->testAccount($connection);
                        Notification::make()->title(__('labels.connection_test_frag').$test['result'])->success($test['result'] === 'PASS')->danger($test['result'] !== 'PASS')->send();
                        $this->saveStep(['connection_id' => $connection->id]);
                    } elseif (! empty($data['connection_id'])) {
                        $this->saveStep(['connection_id' => (int) $data['connection_id']]);
                    } else {
                        Notification::make()->title(__('labels.select_or_connect_an_account_first'))->danger()->send();
                    }
                });

            $connectionId = $state['connection_id'] ?? null;
            $discovered = [];
            if ($isAccountFlow && $connectionId) {
                try {
                    $connection = \App\Models\ExternalAccountConnection::find($connectionId);
                    $discovered = $connection ? $chosen->discoverAccountProjects($connection) : [];
                } catch (\Throwable) {
                    $discovered = [];
                }
            }
            $projectOptions = collect($discovered)->filter(fn ($p) => ! empty($p['ref']))
                ->mapWithKeys(fn ($p) => [$p['ref'] => ($p['name'] ?? $p['ref']).' — '.($p['region'] ?? 'region unknown')])->all();
            $actions[] = Action::make('select_project')->label('4. Select project')->icon('heroicon-o-rectangle-stack')
                ->visible($isAccountFlow)
                ->schema([
                    Select::make('project_ref')->label($chosen?->definition()->name.' project')->options($projectOptions)->required()
                        ->helperText($projectOptions === [] ? 'Connect an account first (projects appear here).' : count($projectOptions).' discovered — single project import.'),
                ])
                ->action(function (array $data) use ($discovered, $chosen) {
                    $connection = \App\Models\ExternalAccountConnection::find($this->session->state['connection_id'] ?? 0);
                    abort_if($connection === null, 422, 'No connected account.');
                    $discoveredProject = collect($discovered)->firstWhere('ref', $data['project_ref']);
                    abort_if($discoveredProject === null, 422, 'Selected project not in discovery result.');
                    $draft = \App\Services\ControlPlane\OnboardingService::prepareImportDraft($this->session, $connection, $discoveredProject, $chosen);
                    $this->session->refresh();
                    Notification::make()->title(__('labels.draft_prepared_project_read_only_source_'))->success()->send();
                    $this->saveStep(['selected_ref' => $data['project_ref']]);
                });

            $actions[] = Action::make('db_credential')->label('5. Database read-only credential')->icon('heroicon-o-key')
                ->visible($isAccountFlow)
                ->schema($this->connectorCredentialForm($chosen, ['password', 'host', 'port', 'database', 'username', 'schema'], $state))
                ->action(function (array $data) {
                    $project = $this->session->project;
                    abort_if($project === null, 422, 'Select a project first.');
                    $source = \App\Models\MigrationSource::where('project_id', $project->id)->orderByDesc('id')->first();
                    abort_if($source === null, 422, 'Select a project first.');
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'migrations.manage');
                    $this->applyCredentialForm($source, $data);
                    \App\Services\ControlPlane\AdminAudit::record('SOURCE_CREDENTIAL_CHANGED', $project, 'migration_source', $source->id);
                    Notification::make()->title(__('labels.read_only_db_credential_stored_vault'))->success()->send();
                });

            // ── Credentials-form flow (generic, schema-driven) ──────────
            $actions[] = Action::make('configure_connector')->label('3. Configure connector')->icon('heroicon-o-adjustments-horizontal')
                ->visible($isCredentialsFlow)
                ->schema($this->connectorCredentialForm($chosen, null, $state))
                ->action(function (array $data) use ($chosen) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'migrations.manage');
                    $draft = \App\Services\ControlPlane\OnboardingService::prepareConnectorDraft($this->session);
                    $project = $draft['project'];
                    $source = $chosen->createSourceProfile($project, [], $this->credentialConfiguration($chosen, $data), \App\Services\ControlPlane\EnvironmentService::defaultFor($project)->id);
                    $this->applyCredentialForm($source, $data);
                    $this->session->refresh();
                    Notification::make()->title(__('labels.source_profile_created_read_only_credent'))->success()->send();
                });

            $actions[] = Action::make('analyze_step')->label('6. Capability probe + analyze')->icon('heroicon-o-magnifying-glass')
                ->action(function () {
                    $project = $this->session->project;
                    abort_if($project === null, 422, 'Choose a source connector and configure it first.');
                    $source = \App\Models\MigrationSource::where('project_id', $project->id)->orderByDesc('id')->first();
                    $probe = \App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe::probe($source);
                    Notification::make()->title(__('labels.capability_probe_frag').json_encode($probe['domains'] ?? $probe))->success()->send();
                    if (($probe['domains']['database'] ?? '') === 'PASS') {
                        $service = new \App\Services\ControlPlane\Migration\MigrationCenterService;
                        $analysis = $service->analyze($source);
                        if ($analysis->status === 'completed') {
                            $service->classify($analysis);
                            Notification::make()->title(__('labels.mc_analyzed', ['count' => (int) collect($analysis->counts)->sum()]))->success()->send();
                        } else {
                            // Classified failure detail stays on the analysis
                            // row (H3); the notification speaks product copy.
                            Notification::make()->title(__('labels.analysis_failed'))
                                ->body(__('labels.mc_analysis_failed_body'))->danger()->send();
                        }
                    }
                });

            $actions[] = Action::make('link_repository')->label('7. Link client repository')->icon('heroicon-o-folder-open')
                ->schema([
                    TextInput::make('display_name')->required(),
                    TextInput::make('root_path')->required()->helperText(__('labels.operator_approved_local_root_scanner_rea')),
                ])
                ->action(function (array $data) {
                    $project = $this->session->project;
                    abort_if($project === null, 422, 'Select a project first.');
                    \App\Services\ControlPlane\Repository\ClientRepositoryService::linkLocal($project, $data['display_name'], $data['root_path']);
                    Notification::make()->title(__('labels.repository_linked_approved_root_persiste'))->success()->send();
                });

            $actions[] = Action::make('scan_client')->label('8. Scan client')->icon('heroicon-o-magnifying-glass-circle')
                ->action(function () {
                    $project = $this->session->project;
                    $repo = $project?->clientRepositories()->orderByDesc('id')->first();
                    abort_if($repo === null, 422, 'Link a repository first.');
                    $result = \App\Services\ControlPlane\Repository\ClientDependencyScanner::scan($repo);
                    $provider = \App\Services\ControlPlane\Repository\ClientDependencyScanner::providers()[0]?->scannerLabel() ?? 'provider';
                    Notification::make()->title($result['callsites'].' '.$provider.' callsites found in '.$result['files'].' files')->success()->send();
                });

            $actions[] = Action::make('copilot_plan')->label('9. AI Copilot plan')->icon('heroicon-o-sparkles')
                ->action(function () {
                    $project = $this->session->project;
                    abort_if($project === null, 422, __('labels.ob_select_project_first'));
                    try {
                        $analysis = \App\Models\MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first();
                        $run = (new \App\Services\ControlPlane\Ai\MigrationCopilot)->run($project, 'explain_blockers', ['analysis' => $analysis]);
                        Notification::make()->title(__('labels.copilot_done', ['action' => __('labels.cp_action_explain_blockers'), 'status' => \App\Support\ProductStatus::label((string) $run->status)]))
                            ->success($run->status === 'completed')->danger($run->status !== 'completed')->send();
                    } catch (\Throwable $e) {
                        // Raw diagnostics stay in the log (H3).
                        report($e);
                        Notification::make()->title(__('labels.copilot_skipped_frag'))
                            ->body(__('labels.copilot_failed_body'))->warning()->send();
                    }
                });
        }

        $actions[] = Action::make('next_step')->label(__('labels.next_step'))->icon('heroicon-o-arrow-right')->color('gray')
            ->action(function () {
                $max = count($this->steps()) - 1;
                $this->session->update(['current_step' => min($max, $this->session->current_step + 1)]);
                $this->session->refresh();
                $this->redirect(self::getUrl());
            });
        $actions[] = Action::make('prev_step')->label(__('labels.back'))->icon('heroicon-o-arrow-left')->color('gray')
            ->action(function () {
                $this->session->update(['current_step' => max(0, $this->session->current_step - 1)]);
                $this->session->refresh();
                $this->redirect(self::getUrl());
            });

        // Final confirmation — the only step that creates anything.
        $isFinish = $this->session->current_step >= count($this->steps()) - 1;
        if ($isFinish) {
            $actions[] = Action::make('finish')->label(__('labels.finish_create_everything'))->icon('heroicon-o-check-circle')->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('labels.this_is_the_point_where_the_platform_act'))
                ->schema([
                    TextInput::make('confirm_name')->required()->default($state['name'] ?? '')
                        ->helperText(__('labels.re_type_the_project_name_to_confirm')),
                ])
                ->action(function (array $data) {
                    $this->session->refresh();
                    if (($this->session->state['name'] ?? '') !== $data['confirm_name']) {
                        Notification::make()->title(__('labels.confirmation_name_does_not_match'))->danger()->send();

                        return;
                    }
                    if ($this->session->flow === 'create') {
                        $project = OnboardingService::completeCreate($this->session);
                        Notification::make()->title(__('labels.project_created_frag').$project->name)->success()->send();

                        return \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]);
                    }
                    $result = OnboardingService::completeImport($this->session);
                    Notification::make()->title(__('labels.project_created_continue_in_the_migratio'))->success()->send();

                    return $result['source']
                        ? \App\Filament\Resources\Projects\Pages\ProjectMigrationCenter::getUrl(['record' => $result['project']])
                        : \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $result['project']]);
                });
        }

        $actions[] = Action::make('abandon')->label(__('labels.abandon'))->icon('heroicon-o-trash')->color('danger')
            ->requiresConfirmation()
            ->action(function () {
                OnboardingService::abandon($this->session);
                $this->session = null;
                $this->redirect(self::getUrl());
            });

        return $actions;
    }

    /**
     * Phase 27G.2 — generic credential form driven by the connector's
     * declarative schema. $keys filters to a subset (null = all fields).
     */
    protected function connectorCredentialForm(?\App\Services\ControlPlane\Connectors\Contracts\Connector $connector, ?array $keys, array $state): array
    {
        if ($connector === null) {
            return [TextInput::make('placeholder')->label(__('labels.choose_a_source_connector_first'))->dehydrated(false)->disabled()];
        }
        $fields = [];
        foreach ($connector->credentialSchema() as $field) {
            if ($keys !== null && ! in_array($field->key, $keys, true)) {
                continue;
            }
            $label = $field->label;
            $previous = $state['connector_config'][$field->key] ?? $field->default;
            if ($field->type === 'select' && $field->options !== null) {
                $component = Select::make($field->key)->label($label)->options($field->options)->default($previous)->native(false);
            } elseif ($field->type === 'port') {
                $component = TextInput::make($field->key)->label($label)->numeric()->default($previous !== null ? (int) $previous : null);
            } elseif ($field->secret) {
                $component = TextInput::make($field->key)->label($label)->password()->revealable();
            } else {
                $component = TextInput::make($field->key)->label($label)->default($previous);
            }
            if ($field->help !== null) {
                $component = $component->helperText($field->help);
            }
            if ($field->required) {
                $component = $component->required();
            }
            $fields[] = $component;
        }

        return $fields;
    }

    /** Non-secret portion of the submitted credential form (for createSourceProfile). */
    protected function credentialConfiguration(\App\Services\ControlPlane\Connectors\Contracts\Connector $connector, array $data): array
    {
        $configuration = ['display_name' => ($this->session->state['name'] ?? 'source').' source'];
        foreach ($connector->credentialSchema() as $field) {
            if ($field->secret) {
                continue;
            }
            if (isset($data[$field->key]) && $data[$field->key] !== '' && $data[$field->key] !== null) {
                $configuration[$field->key] = $data[$field->key];
            }
        }

        return $configuration;
    }

    /**
     * Store submitted credentials on a source: secrets go to the vault
     * (SOURCE_<FIELD> names, 27E.2) and are referenced via secret_refs;
     * non-secret configuration lands on the connection JSON.
     */
    protected function applyCredentialForm(\App\Models\MigrationSource $source, array $data): void
    {
        $connector = \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()
            ->sourceConnector($source->effectiveConnectorKey());
        $connection = $source->connection ?? [];
        $secretRefs = $source->secret_refs ?? [];
        foreach ($connector->credentialSchema() as $field) {
            $value = $data[$field->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($field->secret) {
                $secretName = 'SOURCE_'.strtoupper($field->key);
                \App\Services\ControlPlane\SecretVaultService::createSecret($source->project, $secretName, (string) $value, ['category' => 'database']);
                $secretRefs[$field->key] = $secretName;
            } else {
                $connection[$field->key] = $value;
            }
        }
        $source->update(['connection' => $connection, 'secret_refs' => $secretRefs]);
    }

    protected function saveStep(array $patch): void
    {
        OnboardingService::saveStep($this->session, $this->session->current_step, $patch);
        $this->session->refresh();
        Notification::make()->title(__('labels.step_saved_you_can_leave_and_resume_anyt'))->success()->send();
        $this->redirect(self::getUrl());
    }
}
