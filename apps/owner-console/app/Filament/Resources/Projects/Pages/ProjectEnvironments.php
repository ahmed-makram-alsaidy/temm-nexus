<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectEnvironment;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\EnvironmentService;
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
 * Phase 24C — Environment Manager. Canonical Development / Staging /
 * Production environments with isolation namespaces, session switcher,
 * guarded promotion and environment diff.
 */
class ProjectEnvironments extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        EnvironmentService::ensureDefaults($this->project());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Environments';
    }

    public function getBreadcrumbs(): array
    {
        return ['Environments'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('environments'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $envs = $project->environments()->orderBy('id')->get();
        $active = EnvironmentContext::active($project);

        $rows = '';
        foreach ($envs as $env) {
            $typeBadge = match ($env->type) {
                'production' => '<span class="cp-badge is-danger">production</span>',
                'staging' => '<span class="cp-badge is-warning">staging</span>',
                default => '<span class="cp-badge is-info">development</span>',
            };
            $status = $env->status === 'active' ? '<span class="cp-badge is-success">active</span>' : '<span class="cp-badge">inactive</span>';
            $isCurrent = $env->id === $active->id ? ' <span class="cp-badge is-success">current</span>' : '';
            $dbTarget = $env->database_connection['database'] ?? '(unset)';
            $rows .= '<tr>'
                .'<td><strong>'.e($env->name).'</strong>'.$isCurrent.'</td>'
                .'<td>'.$typeBadge.'</td><td>'.$status.'</td>'
                .'<td><code>'.e($dbTarget).'</code></td>'
                .'<td><code>'.e(EnvironmentService::redisNamespace($project, $env)).'</code></td>'
                .'<td><code>'.e($env->storage_namespace ?? '—').'</code></td>'
                .'<td>'.($env->disposable ? '<span class="cp-badge is-warning">disposable</span>' : '<span class="cp-badge">protected</span>').'</td>'
                .'</tr>';
        }

        // Promotion readiness strip.
        $promo = '';
        foreach ($envs->where('type', 'staging') as $staging) {
            $dev = $envs->firstWhere('type', 'development');
            if ($dev) {
                $diff = EnvironmentService::diff($dev, $staging);
                $same = collect($diff)->where('same', true)->count();
                $promo .= '<tr><td>Development → Staging</td><td>'.$same.'/'.count($diff).' aspects aligned</td></tr>';
            }
        }
        if ($promo === '') {
            $promo = '<tr><td colspan="2">No staging environment yet.</td></tr>';
        }

        return $schema->components([
            Section::make('Environments ('.$envs->count().')')->schema([Html::make(
                '<p style="font-size:.75rem;color:var(--cp-text-dim);margin-bottom:.5rem">Active context: <strong>'.e($project->name).'</strong> / <code>'.e($active->slug).'</code> — '
                .'environment-scoped modules (Secrets, Backups, Resources, Readiness, Migration Center, Connect) follow this selection. '
                .'Each environment isolates database, redis namespace, storage namespace, realtime namespace, secrets and logs.</p>'
                .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                .'<th>Name</th><th>Type</th><th>Status</th><th>DB</th><th>Redis ns</th><th>Storage ns</th><th>Reset</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            )])->compact(),
            Section::make('Promotion posture')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Path</th><th>Alignment</th></tr></thead><tbody>'.$promo.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Promotion is guarded: production promotion requires strong confirmation '
                .'and remains local-only; sensitive data is never copied automatically.</p>'
            )])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return [
            Action::make('switch_environment')->label('Switch environment')->icon('heroicon-o-arrows-pointing-out')
                ->schema([
                    Select::make('environment_id')->label('Environment')->required()->options(
                        $project->environments()->where('status', 'active')->orderBy('id')->pluck('name', 'id')->all()
                    )->default(EnvironmentContext::active($project)->id),
                ])
                ->action(function (array $data) {
                    $ok = EnvironmentContext::switch($this->project(), (int) $data['environment_id']);
                    Notification::make()->title($ok ? 'Environment switched — scoped modules updated' : 'Switch failed')->success($ok)->danger(! $ok)->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('new_environment')->label('New environment')->icon('heroicon-o-plus')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'environments.manage'))
                ->schema([
                    TextInput::make('name')->required(),
                    Select::make('type')->required()->options([
                        'development' => 'Development', 'staging' => 'Staging', 'production' => 'Production',
                    ])->default('development'),
                    TextInput::make('api_base_url')->url()->placeholder('https://api.example.test'),
                    TextInput::make('database')->label('Database name'),
                    TextInput::make('database_secret_ref')->label('DB password vault ref')->placeholder('DB_PASSWORD_STAGING'),
                    Toggle::make('disposable')->default(true)->helperText('Disposable targets may be reset by drills/clean runs.'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'environments.manage');
                    $conn = $data['database'] ? ['database' => $data['database']] : null;
                    EnvironmentService::create($this->project(), [
                        'name' => $data['name'],
                        'type' => $data['type'],
                        'api_base_url' => $data['api_base_url'] ?? null,
                        'database_connection' => $conn,
                        'database_secret_ref' => $data['database_secret_ref'] ?? null,
                        'disposable' => $data['disposable'] ?? false,
                    ]);
                    Notification::make()->title('Environment created')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('promote')->label('Promote to Production')->icon('heroicon-o-rocket-launch')->color('danger')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'environments.manage'))
                ->requiresConfirmation()
                ->modalDescription('Production promotion is STRONGLY guarded and currently local-only. Type the confirmation phrase in the field below.')
                ->schema([
                    Select::make('from')->label('From environment')->options(
                        $project->environments()->whereIn('type', ['development', 'staging'])->pluck('name', 'id')->all()
                    )->required(),
                    TextInput::make('confirmation')->placeholder('PROMOTE '.$project->slug.' TO PRODUCTION')
                        ->helperText('Must match: PROMOTE '.$project->slug.' TO PRODUCTION'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'environments.manage');
                    $from = $project->environments()->findOrFail($data['from']);
                    $prod = $project->environments()->where('type', 'production')->firstOrFail();
                    $result = EnvironmentService::attemptPromotion($from, $prod, true, ['confirmation' => $data['confirmation']]);
                    Notification::make()->title($result['allowed'] ? 'Promotion preconditions met (local-only)' : 'Promotion blocked by guard')
                        ->success($result['allowed'])->danger(! $result['allowed'])->send();
                }),
        ];
    }
}
