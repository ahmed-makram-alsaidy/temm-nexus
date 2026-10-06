<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Support\ProductStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 24A.12 — Migration Center: Source → Analysis → Compatibility →
 * Plan → Runs → Validation → Risks → Artifacts. Generic by design; no
 * project-specific logic lives here.
 */
class ProjectMigrationCenter extends Page
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
        return __('labels.migration_center');
    }

    public function getBreadcrumbs(): array
    {
        return ['Migration Center'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'migrations.manage') || CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('migration-center'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $env = EnvironmentContext::active($project);
        $canManage = CpAccess::allows(auth()->user(), 'migrations.manage');

        $sources = MigrationSource::where('project_id', $project->id)->orderBy('id')->get();
        $sourceRows = '';
        foreach ($sources as $source) {
            $statusBadge = match ($source->status) {
                'ready' => 'is-success', 'error' => 'is-danger', default => '',
            };
            // Phase 27Q.3 — connector instance health (generic).
            $health = \App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe::health($source);
            $healthBadge = match ($health) {
                'CONNECTED' => 'is-success', 'PARTIAL' => 'is-warning', 'ERROR' => 'is-danger', 'DISABLED' => '', default => 'is-info',
            };
            $sourceRows .= '<tr><td><strong>'.e($source->display_name).'</strong></td>'
                .'<td><code>'.e($source->effectiveConnectorKey()).'</code>'.($source->connector_version ? ' <span style="font-size:.7rem;color:var(--cp-text-dim)">v'.e($source->connector_version).'</span>' : '').'</td>'
                .'<td>'.e((string) $source->source_ref).'</td>'
                .'<td><span class="cp-badge '.($source->read_only ? 'is-info' : 'is-warning').'">'.($source->read_only ? 'READ-ONLY SOURCE' : 'read-write').'</span></td>'
                .'<td><span class="cp-badge '.$healthBadge.'">'.e($health).'</span></td>'
                .'<td><span class="cp-badge '.$statusBadge.'">'.e($source->status).'</span></td>'
                .'<td>'.e($source->last_analyzed_at?->format('M j, H:i') ?? 'never').'</td></tr>';
        }
        if ($sourceRows === '') {
            $sourceRows = '<tr><td colspan="7">No migration sources yet. A source is a READ-ONLY connection to the system you migrate FROM.</td></tr>';
        }

        // Phase 27Q.2 — generic connector capability matrix (any connector).
        $latestSource = MigrationSource::where('project_id', $project->id)->orderByDesc('id')->first();
        $capabilityHtml = '<p style="color:var(--cp-text-dim);font-size:.8rem">No source yet — the capability matrix appears once a connector source exists.</p>';
        if ($latestSource) {
            $matrix = \App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe::matrix($latestSource);
            $capRows = '';
            foreach ($matrix as $row) {
                $badge = match ($row['status']) {
                    'SUPPORTED' => 'is-success',
                    'SUPPORTED_WITH_CONFIGURATION' => 'is-warning',
                    'PARTIAL' => 'is-warning',
                    'NOT_APPLICABLE' => '',
                    default => 'is-danger',
                };
                $capRows .= '<tr><td>'.e($row['label']).'</td><td><span class="cp-badge '.$badge.'">'.e($row['status']).'</span></td>'
                    .'<td style="font-size:.75rem;color:var(--cp-text-dim)">'.e($row['detail']).'</td></tr>';
            }
            $capabilityHtml = '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_capability')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.detail')).'</th></tr></thead><tbody>'
                .$capRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">Connector: <code>'.e($latestSource->effectiveConnectorKey()).'</code> — statuses are declared by the connector, never faked.</p>';
        }

        $analysis = MigrationAnalysis::where('project_id', $project->id)->orderByDesc('id')->first();
        $analysisHtml = '<p style="color:var(--cp-text-dim);font-size:.8rem">No analysis yet — analyze a source to inventory it (read-only).</p>';
        $compatHtml = '';
        $riskHtml = '';
        if ($analysis) {
            $counts = $analysis->counts ?? [];
            $countCells = '';
            foreach ($counts as $kind => $n) {
                $countCells .= '<div style="min-width:7rem"><span style="font-size:.7rem;color:var(--cp-text-dim);text-transform:uppercase">'.e($kind).'</span><br><strong>'.e((string) $n).'</strong></div>';
            }
            $statusBadge = $analysis->status === 'completed' ? 'is-success' : ($analysis->status === 'failed' ? 'is-danger' : 'is-warning');
            $analysisHtml = '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem">'.$countCells.'</div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Run <code>'.e($analysis->run_id).'</code> — '
                .'<span class="cp-badge '.$statusBadge.'">'.e($analysis->status).'</span> at '.e($analysis->completed_at?->format('M j, H:i') ?? '—')
                .' — fingerprint <code>'.e(substr((string) $analysis->source_fingerprint, 0, 16)).'…</code></p>';

            $compatCounts = [];
            $riskCounts = [];
            foreach ($analysis->items()->get(['kind', 'compatibility', 'risks']) as $item) {
                if ($item->compatibility) {
                    $compatCounts[$item->compatibility] = ($compatCounts[$item->compatibility] ?? 0) + 1;
                }
                foreach ($item->risks ?? [] as $risk) {
                    $riskCounts[$risk] = ($riskCounts[$risk] ?? 0) + 1;
                }
            }
            foreach ($compatCounts as $label => $n) {
                $compatHtml .= '<span class="cp-badge" style="margin:.1rem">'.e($label).': '.e((string) $n).'</span> ';
            }
            if ($compatHtml === '') {
                $compatHtml = '<span class="cp-badge">'.e(__('labels.mc_compat_empty')).'</span>';
            }
            foreach ($riskCounts as $risk => $n) {
                $riskHtml .= '<span class="cp-badge is-warning" style="margin:.1rem">'.e($risk).' × '.e((string) $n).'</span> ';
            }
            if ($riskHtml === '') {
                $riskHtml = '<span class="cp-badge is-success">'.e(__('labels.mc_risks_empty')).'</span>';
            }
        }

        $plan = MigrationPlan::where('project_id', $project->id)->orderByDesc('id')->first();
        $planHtml = '<p style="color:var(--cp-text-dim);font-size:.8rem">'.e(__('labels.mc_plan_empty')).'</p>';
        $stageRows = '';
        if ($plan) {
            $byStage = $plan->items()->orderBy('stage')->get()->groupBy('stage');
            foreach ($byStage as $stage => $items) {
                $names = $items->take(8)->pluck('source_name')->implode(', ');
                $more = $items->count() > 8 ? ' +'.($items->count() - 8).__('labels.mc_plan_more_suffix') : '';
                $statuses = $items->groupBy('status')->map(fn ($g) => $g->count())->map(fn ($n, $s) => $s.':'.$n)->implode(' ');
                $stageRows .= '<tr><td>'.e((string) $stage).'</td><td>'.e($names.$more).'</td><td style="font-size:.75rem">'.e($statuses).'</td></tr>';
            }
            $planHtml = '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_stage')).'</th><th>'.e(__('labels.th_objects_dependency_order')).'</th><th>'.e(__('labels.th_statuses')).'</th></tr></thead><tbody>'
                .$stageRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">'.e(__('labels.mc_plan_named', ['name' => $plan->name, 'count' => $plan->items()->count()])).' '
                .e(__('labels.mc_plan_ordering')).'</p>';
        }

        $runs = MigrationRun::where('project_id', $project->id)->orderByDesc('id')->limit(10)->get();
        $runRows = '';
        foreach ($runs as $run) {
            $statusBadge = match ($run->status) {
                'completed' => 'is-success', 'failed' => 'is-danger', 'running' => 'is-warning', default => '',
            };
            $p = $run->progress ?? [];
            $runRows .= '<tr><td><code dir="ltr">'.e(substr($run->run_id, 0, 8)).'…</code></td>'
                .'<td><span class="cp-badge '.($run->dry_run ? 'is-info' : ($run->mode === 'rehearsal' ? 'is-warning' : '')).'">'.e(__('migration.sync_mode_'.($run->dry_run ? 'dry_run' : (string) $run->mode))).'</span></td>'
                .'<td><span class="cp-badge '.$statusBadge.'">'.e(ProductStatus::label((string) $run->status)).'</span></td>'
                .'<td>'.e(($p['done'] ?? 0).'/'.($p['total'] ?? 0)).'</td>'
                .'<td><code dir="ltr">'.e($run->target_connection !== null ? json_decode($run->target_connection, true)['database'] ?? '—' : '—').'</code></td>'
                .'<td>'.e($run->finished_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? '—').'</td></tr>';
        }
        if ($runRows === '') {
            $runRows = '<tr><td colspan="6">'.e(__('labels.mc_runs_empty')).'</td></tr>';
        }

        $artifacts = \App\Models\MigrationArtifact::where('project_id', $project->id)->orderByDesc('id')->limit(8)->get();
        $artifactRows = '';
        foreach ($artifacts as $artifact) {
            $artifactRows .= '<tr><td><code dir="ltr">'.e($artifact->kind).'</code></td><td style="font-size:.75rem"><code dir="ltr">'.e($artifact->path).'</code></td>'
                .'<td>'.e($artifact->created_at->locale(app()->getLocale())->translatedFormat('M j, H:i')).'</td></tr>';
        }
        if ($artifactRows === '') {
            $artifactRows = '<tr><td colspan="3">'.e(__('labels.mc_artifacts_empty')).'</td></tr>';
        }

        return $schema->components([
            Section::make(__('labels.mc_sources'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.th_connector')).'</th><th>'.e(__('labels.th_ref')).'</th><th>'.e(__('labels.th_mode')).'</th><th>'.e(__('labels.health')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_last_analyzed')).'</th></tr></thead><tbody>'
                .$sourceRows.'</tbody></table></div>'
            )])->compact(),
            Section::make(__('labels.mc_capabilities'))->schema([Html::make($capabilityHtml)])->compact(),
            Section::make(__('labels.mc_latest_analysis'))->schema([Html::make($analysisHtml)])->compact(),
            Section::make(__('labels.mc_compatibility'))->schema([Html::make($compatHtml)])->compact(),
            Section::make(__('labels.mc_risks'))->schema([Html::make($riskHtml)])->compact(),
            Section::make(__('labels.mc_plan'))->schema([Html::make($planHtml)])->compact(),
            Section::make(__('labels.mc_runs'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.run')).'</th><th>'.e(__('labels.th_mode')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_progress')).'</th><th>'.e(__('labels.th_target_db')).'</th><th>'.e(__('labels.th_finished')).'</th></tr></thead><tbody>'
                .$runRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">'.e(__('labels.mc_guard')).'</p>'
            )])->compact(),
            Section::make(__('labels.mc_artifacts'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_kind')).'</th><th>'.e(__('labels.th_path')).'</th><th>'.e(__('labels.created')).'</th></tr></thead><tbody>'
                .$artifactRows.'</tbody></table></div>'
            )])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return array_filter([
            Action::make('create_source')->label(__('labels.new_source'))->icon('heroicon-o-plus')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'migrations.manage'))
                ->schema([
                    TextInput::make('display_name')->required(),
                    // Phase 27G.1 — source types come from the connector registry.
                    Select::make('type')->options(collect(
                        \App\Services\ControlPlane\Connectors\ConnectorRegistry::instance()->all()
                    )->mapWithKeys(fn ($d, $k) => [$k => $d['name'].' (v'.$d['version'].', '.$d['trust'].')'])->all()
                        + ['sqlite' => 'SQLite (test fixture)'])->default('supabase')->required(),
                    TextInput::make('source_ref')->label(__('labels.project_ref_display_only'))->placeholder('local snapshot 2026-09-19'),
                    TextInput::make('host')->default('postgres'),
                    TextInput::make('port')->numeric()->default(5432),
                    TextInput::make('database')->required(),
                    TextInput::make('username')->default('postgres'),
                    TextInput::make('password_secret')->label(__('labels.password_vault_secret_name'))->placeholder('MIGRATION_SOURCE_DB_PASSWORD')
                        ->helperText(__('labels.never_paste_the_password_reference_a_vau')),
                    TextInput::make('manifest')->label(__('labels.optional_edge_function_manifest_json_imp')),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'migrations.manage');
                    $manifest = null;
                    if (! empty($data['manifest'])) {
                        $decoded = json_decode($data['manifest'], true);
                        abort_if($decoded === null, 422, 'Manifest is not valid JSON');
                        $manifest = ['edge_functions' => $decoded['edge_functions'] ?? [], 'client_dependencies' => $decoded['client_dependencies'] ?? []];
                    }
                    MigrationSource::create([
                        'project_id' => $this->project()->id,
                        'environment_id' => EnvironmentContext::active($this->project())->id,
                        'type' => $data['type'],
                        'display_name' => $data['display_name'],
                        'source_ref' => $data['source_ref'] ?? null,
                        'connection' => array_filter([
                            'host' => $data['host'] ?? null,
                            'port' => $data['port'] ?? null,
                            'database' => $data['database'] ?? null,
                            'username' => $data['username'] ?? null,
                            'manifest' => $manifest,
                        ]),
                        'secret_refs' => $data['password_secret'] ? ['password' => $data['password_secret']] : [],
                        'read_only' => true,
                        'status' => 'pending',
                        'created_by' => auth()->id(),
                    ]);
                    \App\Services\ControlPlane\AdminAudit::record('MIGRATION_SOURCE_CREATED', $this->project(), 'migration_source', null, ['name' => $data['display_name']]);
                    Notification::make()->title(__('labels.source_created_read_only'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('analyze')->label(__('labels.analyze_source'))->icon('heroicon-o-magnifying-glass')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'migrations.manage') && MigrationSource::where('project_id', $project->id)->exists())
                ->schema([Select::make('source_id')->label(__('labels.source'))->required()->options(
                    MigrationSource::where('project_id', $project->id)->pluck('display_name', 'id')->all()
                )])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'migrations.manage');
                    $source = MigrationSource::where('project_id', $this->project()->id)->findOrFail($data['source_id']);
                    $service = new MigrationCenterService;
                    $analysis = $service->analyze($source);
                    if ($analysis->status === 'completed') {
                        $service->classify($analysis);
                        $service->rlsMappingArtifact($analysis);
                        Notification::make()->title(__('labels.analysis_completed_frag').collect($analysis->counts)->sum().' objects inventoried')->success()->send();
                    } else {
                        Notification::make()->title(__('labels.analysis_failed'))->body(($analysis->errors['message'] ?? 'unknown error'))->danger()->send();
                    }
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('generate_plan')->label(__('labels.generate_plan'))->icon('heroicon-o-map')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'migrations.manage') && MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->exists())
                ->schema([Select::make('analysis_id')->label(__('labels.analysis'))->required()->options(
                    MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')
                        ->get()->mapWithKeys(fn ($a) => [$a->id => '#'.$a->id.' '.$a->run_id])->all()
                )])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'migrations.manage');
                    $analysis = MigrationAnalysis::where('project_id', $this->project()->id)->findOrFail($data['analysis_id']);
                    $plan = (new MigrationCenterService)->generatePlan($analysis);
                    Notification::make()->title(__('labels.plan_created_with_frag').$plan->items()->count().' items')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $this->runAction(),
            $this->rehearseAction(),
        ]);
    }

    protected function runAction(): Action
    {
        $project = $this->project();

        return Action::make('start_run')->label(__('labels.start_run'))->icon('heroicon-o-play')->color('warning')
            ->visible(fn () => CpAccess::allows(auth()->user(), 'migrations.manage') && MigrationPlan::where('project_id', $project->id)->exists())
            ->schema([
                Select::make('plan_id')->label(__('labels.plan'))->required()->options(
                    MigrationPlan::where('project_id', $project->id)->orderByDesc('id')->pluck('name', 'id')->all()
                ),
                Select::make('mode')->options(['dry_run' => 'Dry run (no writes)', 'rehearsal' => 'Rehearsal (disposable target)'])->default('dry_run')->required(),
                TextInput::make('target_host')->default('postgres'),
                TextInput::make('target_port')->numeric()->default(5432),
                TextInput::make('target_database')->required(),
                TextInput::make('target_username')->default('postgres'),
                TextInput::make('target_password_secret')->label(__('labels.target_password_vault_secret_name')),
                Toggle::make('target_disposable')->default(true)->helperText(__('labels.required_for_rehearsal_reset')),
            ])
            ->action(function (array $data) {
                CpAccess::require(auth()->user(), 'migrations.manage');
                $plan = MigrationPlan::where('project_id', $this->project()->id)->findOrFail($data['plan_id']);
                $manager = new MigrationRunManager;
                $run = $manager->start($plan, [
                    'mode' => $data['mode'],
                    'target' => array_filter([
                        'host' => $data['target_host'] ?? null,
                        'port' => $data['target_port'] ?? null,
                        'database' => $data['target_database'] ?? null,
                        'username' => $data['target_username'] ?? null,
                        'secret_refs' => $data['target_password_secret'] ? ['password' => $data['target_password_secret']] : [],
                    ]),
                    'target_disposable' => (bool) $data['target_disposable'],
                    'reset' => $data['mode'] === 'rehearsal',
                    'target_environment_type' => EnvironmentContext::active($this->project())->type,
                ]);
                $manager->execute($run);
                Notification::make()->title(__('labels.run_frag').$run->run_id.': '.$run->status)
                    ->success($run->status === 'completed')->danger($run->status === 'failed')->warning(! in_array($run->status, ['completed', 'failed']))->send();
                $this->redirect(static::getUrl(['record' => $this->project()]));
            });
    }

    protected function rehearseAction(): Action
    {
        $project = $this->project();

        return Action::make('rehearse_clean')->label(__('labels.rehearse_clean_migration'))->icon('heroicon-o-arrow-path-rounded-square')->color('danger')
            ->visible(fn () => CpAccess::allows(auth()->user(), 'migrations.manage') && MigrationPlan::where('project_id', $project->id)->exists())
            ->requiresConfirmation()
            ->modalDescription(__('labels.resets_an_explicitly_disposable_target_t'))
            ->schema([
                Select::make('plan_id')->label(__('labels.plan'))->required()->options(
                    MigrationPlan::where('project_id', $project->id)->orderByDesc('id')->pluck('name', 'id')->all()
                ),
                TextInput::make('target_database')->required()->default('migration_rehearsal_target'),
                TextInput::make('target_host')->default('postgres'),
                TextInput::make('target_port')->numeric()->default(5432),
                TextInput::make('target_username')->default('postgres'),
                TextInput::make('target_password_secret')->label(__('labels.target_password_vault_secret_name')),
                Toggle::make('disposable')->label(__('labels.i_confirm_this_target_is_disposable'))->default(false),
            ])
            ->action(function (array $data) {
                CpAccess::require(auth()->user(), 'migrations.manage');
                $plan = MigrationPlan::where('project_id', $this->project()->id)->findOrFail($data['plan_id']);
                $result = (new MigrationRunManager)->rehearseClean($plan, [
                    'host' => $data['target_host'],
                    'port' => $data['target_port'],
                    'database' => $data['target_database'],
                    'username' => $data['target_username'],
                    'secret_refs' => $data['target_password_secret'] ? ['password' => $data['target_password_secret']] : [],
                    'disposable' => (bool) $data['disposable'],
                    'environment_type' => 'development',
                ]);
                Notification::make()->title($result['deterministic'] ? 'Clean rehearsal deterministic — both runs match' : 'Clean rehearsal NOT deterministic — investigate')
                    ->success($result['deterministic'])->danger(! $result['deterministic'])->send();
                $this->redirect(static::getUrl(['record' => $this->project()]));
            });
    }
}
