<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AiPatchRun;
use App\Models\AiProviderConfig;
use App\Models\ClientRepository;
use App\Models\CopilotRun;
use App\Models\MigrationAnalysis;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\MigrationCopilot;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 25K — Migration Copilot UI. Action-card driven: every run is
 * operator-initiated, evidence-referenced, and audited. Builder patches land
 * in the isolated workspace for review — never applied directly.
 */
class ProjectCopilot extends Page
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
        return __('labels.ai_copilot');
    }

    public function getBreadcrumbs(): array
    {
        return ['AI Copilot'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'copilot.run') || CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('copilot'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $env = EnvironmentContext::active($project);
        $providers = AiProviderConfig::where(function ($q) use ($project) {
            $q->whereNull('project_id')->orWhere('project_id', $project->id);
        })->orderByDesc('id')->get();
        $analysis = MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first();
        $repo = ClientRepository::where('project_id', $project->id)->orderByDesc('id')->first();

        $providerRows = '';
        foreach ($providers as $provider) {
            $providerRows .= '<tr><td><strong>'.e($provider->display_name).'</strong></td>'
                .'<td><code>'.e($provider->provider).'</code></td>'
                .'<td><code>'.e($provider->model ?? '—').'</code></td>'
                .'<td>'.($provider->project_id ? '<span class="cp-badge">project</span>' : '<span class="cp-badge is-info">global</span>').'</td>'
                .'<td><span class="cp-badge '.($provider->status === 'connected' ? 'is-success' : ($provider->status === 'error' ? 'is-danger' : '')).'">'.e($provider->status).'</span></td>'
                .'</tr>';
        }
        if ($providerRows === '') {
            $providerRows = '<tr><td colspan="5">No AI provider configured yet — add one to enable the Copilot.</td></tr>';
        }

        $runs = CopilotRun::where('project_id', $project->id)->orderByDesc('id')->limit(12)->get();
        $runRows = '';
        foreach ($runs as $run) {
            $badge = match ($run->status) {
                'completed' => 'is-success', 'failed' => 'is-danger', default => 'is-warning',
            };
            $modeBadge = ['advisor' => 'is-info', 'builder' => 'is-warning', 'validator' => 'is-success'][$run->mode] ?? '';
            $runRows .= '<tr><td><code>'.e(substr($run->run_id, 0, 8)).'…</code></td>'
                .'<td><span class="cp-badge '.$modeBadge.'">'.e($run->mode).'</span></td>'
                .'<td>'.e($run->action).'</td>'
                .'<td><span class="cp-badge '.$badge.'">'.e($run->status).'</span></td>'
                .'<td>'.e($run->provider ?? '—').'/'.e($run->model ?? '—').'</td>'
                .'<td>'.e($run->created_at->format('M j, H:i')).'</td></tr>';
        }
        if ($runRows === '') {
            $runRows = '<tr><td colspan="6">No Copilot runs yet.</td></tr>';
        }

        $patches = AiPatchRun::where('project_id', $project->id)->orderByDesc('id')->limit(8)->get();
        $patchRows = '';
        foreach ($patches as $patch) {
            $badge = ['proposed' => 'is-warning', 'approved' => 'is-info', 'applied' => 'is-success', 'rejected' => 'is-danger'][$patch->status] ?? '';
            $patchRows .= '<tr><td><code>'.e(substr($patch->run_id, 0, 8)).'…</code></td>'
                .'<td>'.e((string) $patch->files()->count()).' files</td>'
                .'<td><span class="cp-badge '.$badge.'">'.e($patch->status).'</span></td>'
                .'<td>'.e($patch->created_at->format('M j, H:i')).'</td></tr>';
        }
        if ($patchRows === '') {
            $patchRows = '<tr><td colspan="4">No patch runs yet. Generated patches always await explicit review + approval.</td></tr>';
        }

        $context = 'Analysis: '.($analysis ? '#'.$analysis->id.' ('.substr($analysis->run_id, 0, 8).'…)' : 'none')
            .' · Repository: '.($repo ? e($repo->display_name) : 'not linked')
            .' · Environment: '.e($env->slug);

        return $schema->components([
            Section::make('AI providers')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Name</th><th>Provider</th><th>Model</th><th>Scope</th><th>Status</th></tr></thead><tbody>'
                .$providerRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">API keys are encrypted at rest and never rendered. '
                .'Privacy: only minimized/redacted project material is sent to providers — never secrets, hashes or row data.</p>'
            )])->compact(),
            Section::make('Context — '.$context)->schema([Html::make(
                '<p style="font-size:.8rem;color:var(--cp-text-dim)">Runs consume platform artifacts (analysis, RLS/RPC/edge inventories, callsite manifest, '
                .'selected files) through scoped, redacted context packs. Source content is treated as UNTRUSTED DATA.</p>'
            )])->compact(),
            Section::make('Copilot run history')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Run</th><th>Mode</th><th>Action</th><th>Status</th><th>Provider/Model</th><th>When</th></tr></thead><tbody>'
                .$runRows.'</tbody></table></div>'
            )])->compact(),
            Section::make('Patch runs — PLAN → PATCH → REVIEW → APPROVE → APPLY')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Run</th><th>Files</th><th>Status</th><th>When</th></tr></thead><tbody>'
                .$patchRows.'</tbody></table></div>'
            )])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();
        $analysis = MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first();
        $repo = ClientRepository::where('project_id', $project->id)->orderByDesc('id')->first();
        $hasProvider = AiProviderConfig::where('enabled', true)->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))->exists();

        return array_filter([
            Action::make('add_provider')->label(__('labels.add_ai_provider'))->icon('heroicon-o-plus')
                ->schema([
                    // 0.4.0-rc.5 (A.7): fake/test providers never appear in a
                    // production UI — NexusAiConfig filters them.
                    Select::make('provider')->options(collect(\App\Services\Ai\NexusAiConfig::selectableProviders())
                        ->map(fn ($label, $key) => $key === 'openai_compatible' ? $label.' (custom URL)' : $label)
                        ->all())->required(),
                    TextInput::make('display_name')->required(),
                    TextInput::make('base_url')->url()->helperText(__('labels.required_for_openai_compatible_https_onl')),
                    TextInput::make('model')->required(),
                    TextInput::make('api_key')->password()->revealable()->helperText(__('labels.encrypted_at_rest_never_displayed_again')),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'copilot.run');
                    AiProviderConfig::create([
                        'provider' => $data['provider'],
                        'display_name' => $data['display_name'],
                        'base_url' => $data['base_url'] ?? null,
                        'model' => $data['model'],
                        'secret_encrypted' => $data['api_key'] ?? '',
                        'enabled' => true,
                        'project_id' => $this->project()->id,
                    ]);
                    \App\Services\ControlPlane\AdminAudit::record('AI_PROVIDER_SAVED', $this->project(), 'ai_provider_config', null, ['provider' => $data['provider']]);
                    Notification::make()->title(__('labels.ai_provider_saved_key_encrypted'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $hasProvider ? null : null,
            Action::make('explain_blockers')->label(__('labels.explain_blockers'))->icon('heroicon-o-light-bulb')
                ->visible(fn () => $hasProvider && $analysis)
                ->requiresConfirmation()->modalDescription(__('labels.runs_the_copilot_advisor_read_only'))
                ->action(fn () => $this->runAction('explain_blockers')),
            Action::make('analyze_rls')->label(__('labels.analyze_rls'))->icon('heroicon-o-shield-check')
                ->visible(fn () => $hasProvider && $analysis)
                ->action(fn () => $this->runAction('analyze_rls')),
            Action::make('classify_rpc')->label(__('labels.map_rpcs'))->icon('heroicon-o-variable')
                ->visible(fn () => $hasProvider && $analysis)
                ->action(fn () => $this->runAction('classify_rpc')),
            Action::make('classify_edge')->label(__('labels.analyze_edge_functions'))->icon('heroicon-o-cloud')
                ->visible(fn () => $hasProvider && $analysis)
                ->action(fn () => $this->runAction('classify_edge')),
            Action::make('map_client_calls')->label(__('labels.analyze_client_calls'))->icon('heroicon-o-device-phone-mobile')
                ->visible(fn () => $hasProvider && $repo)
                ->action(fn () => $this->runAction('map_client_calls')),
            Action::make('generate_patch')->label(__('labels.generate_patch'))->icon('heroicon-o-code-bracket')->color('warning')
                ->visible(fn () => $hasProvider && $repo)
                ->requiresConfirmation()->modalDescription(__('labels.patches_go_to_the_isolated_ai_workspace_'))
                ->schema([
                    \Filament\Forms\Components\Textarea::make('focus_files')->rows(2)->placeholder('lib/data/api_client.dart')->helperText(__('labels.optional_files_to_include_one_per_line')),
                ])
                ->action(function (array $data) {
                    $repo = ClientRepository::where('project_id', $this->project()->id)->orderByDesc('id')->first();
                    $focus = array_values(array_filter(array_map('trim', explode("\n", (string) $data['focus_files']))));
                    $this->runAction('generate_patch', ['repository' => $repo, 'focus_files' => $focus]);
                }),
            Action::make('approve_patch')->label(__('labels.review_approve_patch'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => AiPatchRun::where('project_id', $project->id)->where('status', 'proposed')->exists())
                ->schema([
                    Select::make('patch_run_id')->required()->options(
                        AiPatchRun::where('project_id', $project->id)->where('status', 'proposed')->pluck('run_id', 'id')->all()
                    ),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'repositories.manage');
                    $run = AiPatchRun::where('project_id', $this->project()->id)->findOrFail($data['patch_run_id']);
                    $files = $run->files()->where('status', 'proposed')->get();
                    $listing = $files->map(fn ($f) => $f->action.' '.e($f->path).' ('.$f->risk.') — '.\Illuminate\Support\Str::limit((string) $f->reason, 80))->implode("\n");
                    session(["cp_patch_listing_{$this->project()->id}" => $listing]);
                    \App\Services\ControlPlane\Ai\PatchWorkspace::approve($run, auth()->user());
                    Notification::make()->title(__('labels.patch_approved_use_apply_to_write_it_int'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('apply_patch')->label(__('labels.apply_approved_patch'))->icon('heroicon-o-arrow-down-tray')->color('danger')
                ->visible(fn () => AiPatchRun::where('project_id', $project->id)->where('status', 'approved')->exists())
                ->requiresConfirmation()->modalDescription(__('labels.applies_the_approved_patch_into_the_appr'))
                ->schema([
                    Select::make('patch_run_id')->required()->options(
                        AiPatchRun::where('project_id', $project->id)->where('status', 'approved')->pluck('run_id', 'id')->all()
                    ),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'repositories.manage');
                    $run = AiPatchRun::where('project_id', $this->project()->id)->findOrFail($data['patch_run_id']);
                    $applied = \App\Services\ControlPlane\Ai\PatchWorkspace::apply($run, auth()->user());
                    Notification::make()->title(__('labels.patch_apply_frag').$applied->status)->success($applied->status === 'applied')->danger($applied->status !== 'applied')->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }

    protected function runAction(string $action, array $context = []): void
    {
        $project = $this->project();
        try {
            $context = $context + [
                'analysis' => MigrationAnalysis::where('project_id', $project->id)->where('status', 'completed')->orderByDesc('id')->first(),
                'repository' => ClientRepository::where('project_id', $project->id)->orderByDesc('id')->first(),
            ];
            $run = (new MigrationCopilot(new AiGateway))->run($project, $action, $context);
            Notification::make()->title(__('labels.copilot_frag').$action.': '.$run->status)
                ->body($run->status === 'completed' ? 'Result stored — see run history.' : (string) ($run->result['error'] ?? ''))
                ->success($run->status === 'completed')->danger($run->status === 'failed')->send();
        } catch (\Throwable $e) {
            Notification::make()->title(\Illuminate\Support\Str::limit($e->getMessage(), 160))->danger()->send();
        }
        $this->redirect(static::getUrl(['record' => $project]));
    }
}
