<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\SchemaSnapshot;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\SchemaDiffService;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Phase 24E — Schema Diff / Drift Detection. Snapshots per environment,
 * side-by-side structured diff with severity classification, and migration
 * drift against the project checkout.
 */
class ProjectSchemaDiff extends Page
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
        return __('labels.schema_diff');
    }

    public function getBreadcrumbs(): array
    {
        return ['Schema Diff'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([
            $this->subnavSection('schema-diff'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $snapshots = SchemaSnapshot::where('project_id', $project->id)->orderByDesc('id')->limit(20)->get();
        $envNameOf = fn ($id) => $id ? (\App\Models\ProjectEnvironment::find($id)?->name ?? "env #{$id}") : 'project';

        $snapRows = '';
        foreach ($snapshots as $snap) {
            $snapRows .= '<tr><td>#'.$snap->id.'</td><td>'.e($envNameOf($snap->environment_id)).'</td>'
                .'<td>'.e($snap->source).'</td><td><code>'.e(substr($snap->fingerprint, 0, 16)).'…</code></td>'
                .'<td>'.e($snap->created_at->format('M j, H:i')).'</td></tr>';
        }
        if ($snapRows === '') {
            $snapRows = '<tr><td colspan="5">No snapshots yet — capture one from the live database or compare below.</td></tr>';
        }

        // Latest diff result (kept in session by the compare action).
        $diffRows = session("cp_schema_diff_{$project->id}", []);
        $diffHtml = '';
        if ($diffRows !== []) {
            $badge = ['DANGEROUS' => 'is-danger', 'REVIEW' => 'is-warning', 'SAFE' => 'is-success', 'INFO' => 'is-info'];
            foreach ($diffRows as $row) {
                $diffHtml .= '<tr><td><span class="cp-badge '.($badge[$row['severity']] ?? '').'">'.e($row['severity']).'</span></td>'
                    .'<td>'.e($row['kind']).'</td><td><code>'.e($row['object']).'</code></td>'
                    .'<td>'.e($row['change']).'</td><td>'.e($row['detail']).'</td></tr>';
            }
            $diffHtml = '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Severity</th><th>Kind</th><th>Object</th><th>Change</th><th>Detail</th></tr></thead><tbody>'
                .$diffHtml.'</tbody></table></div>';
        }

        return $schema->components([
            Section::make('Snapshots')->schema([Html::make(
                '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>ID</th><th>Environment</th><th>Source</th><th>Fingerprint</th><th>Captured</th></tr></thead><tbody>'
                .$snapRows.'</tbody></table></div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Fingerprints are deterministic sha256 over schema shape (tables, columns, types, '
                .'nullability, defaults, PK, FK, indexes, views, functions, triggers).</p>'
            )])->compact(),
            Section::make('Latest diff')->schema([Html::make($diffHtml ?: '<p style="color:var(--cp-text-dim);font-size:.8rem">Run a comparison to populate this panel.</p>')])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();
        $envOptions = $project->environments()->orderBy('id')->pluck('name', 'id')->all();

        return [
            Action::make('capture_snapshot')->label(__('labels.capture_snapshot'))->icon('heroicon-o-camera')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'database.read'))
                ->schema([
                    \Filament\Forms\Components\Select::make('environment_id')->label(__('labels.environment'))->options($envOptions),
                ])
                ->action(function (array $data) {
                    $project = $this->project();
                    $env = $data['environment_id'] ? $project->environments()->find($data['environment_id']) : null;
                    try {
                        $connectionName = ProjectConnectionManager::connection($project);
                        $config = config("database.connections.{$connectionName}");
                        $inventory = SchemaDiffService::introspect(
                            (string) ($config['host'] ?? '127.0.0.1'),
                            (int) ($config['port'] ?? 5432),
                            (string) $config['database'],
                            (string) ($config['username'] ?? 'postgres'),
                            (string) ($config['password'] ?? '')
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->title(__('labels.live_introspection_unavailable'))->body(\Illuminate\Support\Str::limit($e->getMessage(), 200))->danger()->send();

                        return;
                    }
                    SchemaDiffService::snapshot($project, $env?->id, 'live', $inventory, now()->format('M j H:i'), auth()->id());
                    Notification::make()->title(__('labels.snapshot_captured'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $project]));
                }),
            Action::make('compare_envs')->label(__('labels.compare_environments'))->icon('heroicon-o-arrows-right-left')
                ->visible(fn () => CpAccess::allows(auth()->user(), 'database.read'))
                ->schema([
                    \Filament\Forms\Components\Select::make('a')->label(__('labels.environment_a'))->options($envOptions)->required(),
                    \Filament\Forms\Components\Select::make('b')->label(__('labels.environment_b'))->options($envOptions)->required()->different('a'),
                ])
                ->action(function (array $data) use ($project) {
                    $snapA = SchemaSnapshot::where('project_id', $project->id)->where('environment_id', $data['a'])->orderByDesc('id')->first();
                    $snapB = SchemaSnapshot::where('project_id', $project->id)->where('environment_id', $data['b'])->orderByDesc('id')->first();
                    if (! $snapA || ! $snapB) {
                        Notification::make()->title(__('labels.both_environments_need_a_snapshot_first'))->danger()->send();

                        return;
                    }
                    $rows = SchemaDiffService::diff($snapA->snapshot, $snapB->snapshot);
                    session(["cp_schema_diff_{$project->id}" => array_slice($rows, 0, 200)]);
                    $dangerous = SchemaDiffService::hasDangerous($rows);
                    if ($dangerous) {
                        \App\Services\ControlPlane\ReadinessService::recordDrift($project, $project->environments()->find($data['b']), true, 'dangerous drift between environments');
                        \App\Services\ControlPlane\AdminAudit::record('SCHEMA_DIFF_RUN', $project, 'schema_snapshot', $snapB->id, ['dangerous' => true]);
                    }
                    Notification::make()->title(count($rows).' differences ('.collect($rows)->where('severity', 'DANGEROUS')->count().' dangerous)')
                        ->warning($dangerous)->success(! $dangerous)->send();
                    $this->redirect(static::getUrl(['record' => $project]));
                }),
        ];
    }
}
