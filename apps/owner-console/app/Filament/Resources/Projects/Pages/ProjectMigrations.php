<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectSchemaChange;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ProjectArtisan;
use App\Services\ControlPlane\ProjectBackupService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20S migrations & schema history. Checkout migration status/run/
 * rollback need a deployed project checkout (absent locally — shown honestly);
 * every visual-tool schema change is traceably recorded regardless.
 */
class ProjectMigrations extends Page
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
        return 'Migrations';
    }

    public function getBreadcrumbs(): array
    {
        return [static::projectUrl($this->project(), 'database') => 'Tables', 'Migrations'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'database.read');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('migrations'), EmbeddedSchema::make('infolist')]);
    }

    protected function checkout(): ?string
    {
        try {
            $result = ProjectArtisan::run($this->project(), 'migrate:status');
        } catch (\Throwable) {
            return null;
        }

        return $result['ok'] ? $result['output'] : null;
    }

    public function infolist(Schema $schema): Schema
    {
        $status = $this->checkout();
        $changes = ProjectSchemaChange::query()->where('project_id', $this->project()->id)
            ->orderByDesc('id')->limit(50)->get();
        $rows = '';
        foreach ($changes as $c) {
            $rows .= '<tr><td style="white-space:nowrap">'.e($c->created_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td><code>'.e($c->kind).'</code></td>'
                .'<td><code>'.e(mb_substr(json_encode($c->detail), 0, 200)).'</code></td>'
                .'<td>'.e($c->owner_user_id ? '#'.$c->owner_user_id : 'system').'</td></tr>';
        }

        return $schema->components([
            Section::make('Checkout migrations')->schema([
                Html::make($status === null
                    ? '<div class="cp-empty"><div class="cp-empty__title">No project checkout on this host</div>'
                      .'<div class="cp-empty__hint">migrate:status/run/rollback execute inside the deployed project checkout. '
                      .'Local checkouts carry storage only, so these actions are unavailable here — the change history below '
                      .'still records every visual-tool modification.</div></div>'
                    : '<div class="cp-code">'.e($status).'</div>'),
            ])->headerActions($this->migrationActions($status !== null)),
            Section::make('Schema change history ('.$changes->count().')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>Time</th><th>Change</th><th>Detail</th><th>Owner</th>'
                    .'</tr></thead><tbody>'.($rows ?: '<tr><td colspan="4">No visual-tool changes recorded yet.</td></tr>')
                    .'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Table/column/FK/index/trigger/function operations '
                    .'from Database Studio are traced here instead of becoming invisible manual changes.</p>'),
            ])->compact(),
        ]);
    }

    /** @return list<Action> */
    protected function migrationActions(bool $checkoutPresent): array
    {
        if (! $checkoutPresent || ! CpAccess::allows(auth()->user(), 'database.write')) {
            return [];
        }

        return [
            Action::make('migrate_run')->label('Run pending migrations')
                ->requiresConfirmation()
                ->modalDescription('Runs migrate --force in the project checkout. Take a backup first if this is not a disposable project.')
                ->action(function () {
                    CpAccess::require(auth()->user(), 'database.write');
                    $result = ProjectArtisan::run($this->project(), 'migrate', ['--force' => true]);
                    abort_unless($result['ok'], 422, 'Migration failed: '.mb_substr($result['output'], 0, 400));
                    $this->audit('MIGRATION_RUN', 'migration', null, ['output' => mb_substr($result['output'], 0, 500)]);
                    Notification::make()->title('Migrations applied')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('migrate_rollback')->label('Rollback last batch')
                ->color('danger')->requiresConfirmation()
                ->modalDescription('Rolls back the last migration batch. Blind production rollback is dangerous — verify backups first.')
                ->action(function () {
                    CpAccess::require(auth()->user(), 'database.write');
                    $backup = ProjectBackupService::for($this->project())->lastBackup();
                    abort_unless($backup, 422, 'Refusing rollback with no backup on record. Trigger a backup first.');
                    $result = ProjectArtisan::run($this->project(), 'migrate:rollback', ['--force' => true]);
                    abort_unless($result['ok'], 422, 'Rollback failed: '.mb_substr($result['output'], 0, 400));
                    $this->audit('MIGRATION_ROLLED_BACK', 'migration', null, ['output' => mb_substr($result['output'], 0, 500)]);
                    Notification::make()->title('Rollback complete')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }
}
