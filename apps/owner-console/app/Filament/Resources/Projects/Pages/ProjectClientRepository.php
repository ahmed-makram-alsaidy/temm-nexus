<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ClientCallsite;
use App\Models\ClientRepository;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
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
 * Phase 25D/E — Link Client Repository + dependency scan.
 * LOCAL PATH roots are operator-approved and every read is guarded;
 * GIT links are metadata-only in this phase.
 */
class ProjectClientRepository extends Page
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
        return __('labels.client_repository');
    }

    public function getBreadcrumbs(): array
    {
        return ['Client Repository'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'repositories.manage') || CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('client-repository'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $repos = ClientRepository::where('project_id', $project->id)->orderByDesc('id')->get();

        $repoRows = '';
        foreach ($repos as $repo) {
            $badge = $repo->source_type === 'local' ? '<code dir="ltr">'.e($repo->root_path ?? '').'</code>' : '<code dir="ltr">'.e($repo->git_url ?? '').'</code>';
            $repoRows .= '<tr><td><strong>'.e($repo->display_name).'</strong></td>'
                .'<td><span class="cp-badge">'.e(__('labels.crep_source_'.(string) $repo->source_type)).'</span></td>'
                .'<td>'.$badge.'</td>'
                .'<td><span class="cp-badge is-info"><code dir="ltr">'.e($repo->framework ?? '').'</code></span></td>'
                .'<td><span class="cp-badge '.($repo->status === 'scanned' ? 'is-success' : '').'">'.e(__('labels.crep_status_'.(string) $repo->status)).'</span></td>'
                .'<td>'.e($repo->last_scanned_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? __('labels.crep_never_scanned')).'</td></tr>';
        }
        if ($repoRows === '') {
            $repoRows = '<tr><td colspan="6">'.e(__('labels.crep_empty')).'</td></tr>';
        }

        $latest = $repos->firstWhere('status', 'scanned');
        $scanHtml = '<p style="color:var(--cp-text-dim);font-size:.8rem">'.e(__('labels.crep_scan_hint')).'</p>';
        if ($latest) {
            $byCategory = ClientCallsite::where('client_repository_id', $latest->id)
                ->selectRaw('category, COUNT(*) AS n')->groupBy('category')->pluck('n', 'category')->all();
            $cells = '';
            foreach (['auth', 'database', 'rpc', 'functions', 'storage', 'realtime', 'url', 'client_init', 'secret'] as $cat) {
                $cells .= '<div style="min-width:6rem"><span style="font-size:.7rem;color:var(--cp-text-dim);text-transform:uppercase" dir="ltr">'.e($cat).'</span><br><strong>'.e((string) ($byCategory[$cat] ?? 0)).'</strong></div>';
            }
            $secrets = ClientDependencyScanner::secretFindings($latest);
            $secretRows = '';
            foreach (array_slice($secrets, 0, 8) as $s) {
                $secretRows .= '<tr><td><code dir="ltr">'.e($s['file']).'</code></td><td><span class="cp-badge is-danger"><code dir="ltr">'.e($s['marker']).'</code></span></td>'
                    .'<td><code dir="ltr">'.e($s['evidence']).'</code></td><td>'.e(__('labels.crep_finding_'.(string) $s['status'])).'</td></tr>';
            }
            if ($secretRows === '') {
                $secretRows = '<tr><td colspan="4"><span class="cp-badge is-success">'.e(__('labels.crep_no_secrets')).'</span></td></tr>';
            }
            $scanHtml = '<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.6rem">'.$cells.'</div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('labels.crep_secret_storage_note')).'</p>'
                .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.file')).'</th><th>'.e(__('labels.th_marker')).'</th><th>'.e(__('labels.th_evidence')).'</th><th>'.e(__('labels.status')).'</th></tr></thead><tbody>'.$secretRows.'</tbody></table></div>';
        }

        return $schema->components([
            Section::make(__('labels.crep_linked_title'))->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.erd_type')).'</th><th>'.e(__('labels.th_root_url')).'</th><th>'.e(__('labels.th_framework')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_scanned')).'</th></tr></thead><tbody>'
                .$repoRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">'.e(__('labels.crep_local_note')).' '
                .e(__('labels.crep_git_note')).'</p>'
            )])->compact(),
            Section::make(__('labels.crep_latest_scan', ['scanner' => \App\Services\ControlPlane\Repository\ClientDependencyScanner::providers()[0]?->scannerLabel()]))->schema([Html::make($scanHtml)])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return [
            Action::make('link_local')->label(__('labels.link_local_repository'))->icon('heroicon-o-folder-open')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'repositories.manage'))
                ->schema([
                    TextInput::make('display_name')->required(),
                    TextInput::make('root_path')->required()->placeholder('/srv/client-repos/example')
                        ->helperText(__('labels.the_operator_approves_this_exact_root_th')),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'repositories.manage');
                    try {
                        ClientRepositoryService::linkLocal($this->project(), $data['display_name'], $data['root_path']);
                        Notification::make()->title(__('labels.repository_linked_approved_root_persiste'))->success()->send();
                    } catch (\Throwable $e) {
                        // Raw diagnostics stay in the log; the notification
                        // follows the product error pattern (H3).
                        report($e);
                        Notification::make()->title(__('labels.repo_link_failed_title'))
                            ->body(__('labels.repo_link_failed_body'))->danger()->send();
                    }
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('link_git')->label(__('labels.link_git_repository_metadata'))->icon('heroicon-o-link')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'repositories.manage'))
                ->schema([
                    TextInput::make('display_name')->required(),
                    TextInput::make('git_url')->required()->url(),
                    TextInput::make('git_branch')->default('main'),
                    TextInput::make('credential_ref')->label(__('labels.credential_vault_ref_optional')),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'repositories.manage');
                    ClientRepositoryService::linkGit($this->project(), $data['display_name'], $data['git_url'], $data['git_branch'], $data['credential_ref'] ?? null);
                    Notification::make()->title(__('labels.git_repository_linked_metadata_model'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('scan')->label(__('labels.scan_dependencies'))->icon('heroicon-o-magnifying-glass')
                ->visible(fn () => ClientRepository::where('project_id', $project->id)->where('source_type', 'local')->exists())
                ->schema([Select::make('repository_id')->label(__('labels.repository'))->required()->options(
                    ClientRepository::where('project_id', $project->id)->where('source_type', 'local')->pluck('display_name', 'id')->all()
                )])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'repositories.manage');
                    $repo = ClientRepository::where('project_id', $this->project()->id)->findOrFail($data['repository_id']);
                    $result = ClientDependencyScanner::scan($repo);
                    Notification::make()->title(__('labels.repo_scan_done', ['callsites' => (int) $result['callsites'], 'files' => (int) $result['files']]))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }
}
