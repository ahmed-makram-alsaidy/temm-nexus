<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Models\BackupDestination;
use App\Models\BackupPolicy;
use App\Models\BackupRecord;
use App\Models\BackupRun;
use App\Services\ControlPlane\BackupCenterService;
use App\Services\ControlPlane\ProjectBackupService;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Backups UI over the Phase 9 system: trigger + verify from the dashboard,
 * full history, prominent last/backup/verified/drill states.
 * Restore stays a deliberate runbook operation — there is NO restore button.
 */
class ProjectBackups extends Page implements HasTable
{
    use HasProjectContext;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.backups');
    }

    public function getBreadcrumbs(): array
    {
        return ['Backups'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('backups'),
            EmbeddedSchema::make('infolist'),
            EmbeddedTable::make(),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $project = $this->project();
        $stats = ProjectBackupService::for($project)->stats();
        $fmt = fn ($r) => $r ? $r->finished_at?->toDateTimeString().' ('.$r->status.')' : 'never';

        $verifiedAt = $stats['last_verified'] ? $stats['last_verified']->verified_at?->toDateTimeString() : null;

        // Phase 24D — Backup Center: policies, destinations, drills, health.
        $health = BackupCenterService::health($project);
        $healthBadge = match ($health['overall']) {
            BackupCenterService::HEALTHY => 'is-success',
            BackupCenterService::FAILED => 'is-danger',
            BackupCenterService::NEVER_TESTED => 'is-warning',
            default => 'is-warning',
        };
        $policies = BackupPolicy::where('project_id', $project->id)->orderBy('id')->get();
        $policyRows = '';
        foreach ($policies as $policy) {
            $row = collect($health['policies'])->firstWhere('policy', $policy->name) ?? [];
            $policyRows .= '<tr><td><strong>'.e($policy->name).'</strong></td>'
                .'<td><code>'.e($policy->scope).'</code></td><td><code>'.e($policy->schedule).'</code></td>'
                .'<td>'.e((string) $policy->retention_days).'d</td>'
                .'<td><span class="cp-badge '.(($row['status'] ?? '') === 'HEALTHY' ? 'is-success' : 'is-warning').'">'.e($row['status'] ?? '—').'</span></td>'
                .'<td><span class="cp-badge '.(($row['restore'] ?? '') === 'HEALTHY' ? 'is-success' : 'is-warning').'">'.e($row['restore'] ?? 'NEVER_TESTED').'</span></td>'
                .'<td>'.e($policy->last_run_at?->format('M j, H:i') ?? 'never').'</td></tr>';
        }
        if ($policyRows === '') {
            $policyRows = '<tr><td colspan="7">No policies yet — add one to schedule and monitor backups.</td></tr>';
        }
        $drills = BackupRun::where('project_id', $project->id)->where('type', 'restore_drill')->orderByDesc('id')->limit(6)->get();
        $drillRows = '';
        foreach ($drills as $drill) {
            $badge = $drill->status === 'drill_passed' ? 'is-success' : ($drill->status === 'drill_failed' ? 'is-danger' : 'is-warning');
            $drillRows .= '<tr><td>#'.$drill->id.'</td><td><span class="cp-badge '.$badge.'">'.e($drill->status).'</span></td>'
                .'<td>'.e($drill->finished_at?->format('M j, H:i') ?? 'running…').'</td>'
                .'<td style="font-size:.75rem">'.e((string) ($drill->meta['tables_restored'] ?? '—')).' tables</td>'
                .'<td style="font-size:.75rem">'.e(\Illuminate\Support\Str::limit((string) $drill->error, 80)).'</td></tr>';
        }
        if ($drillRows === '') {
            $drillRows = '<tr><td colspan="5">No restore drills yet. Drills restore into a NEW disposable database and never touch the active DB.</td></tr>';
        }
        $destinations = BackupDestination::where('project_id', $project->id)->orderBy('id')->get();
        $destRows = '';
        foreach ($destinations as $dest) {
            $destRows .= '<tr><td><strong>'.e($dest->name).'</strong></td><td><code>'.e($dest->driver).'</code></td>'
                .'<td style="font-size:.75rem"><code>'.e(($dest->config['endpoint'] ?? '')).($dest->config['bucket'] ?? '' ? ' / '.e($dest->config['bucket']) : '').'</code></td>'
                .'<td>'.($dest->secret_ref ? '<code>'.e($dest->secret_ref).'</code>' : '<span class="cp-badge">no offsite key</span>').'</td></tr>';
        }
        if ($destRows === '') {
            $destRows = '<tr><td colspan="4">No offsite destinations. S3-compatible endpoints (R2/B2/MinIO/S3) reference vault keys by name — never pasted here.</td></tr>';
        }

        return $schema->components([
            Section::make('Status')->schema([
                TextEntry::make('last')->label(__('labels.latest_backup'))->state($stats['last_backup'] ? $stats['last_backup']->finished_at?->toDateTimeString().' · '.$stats['last_backup']->status : 'none yet')
                    ->badge()->color($stats['last_backup'] ? 'success' : 'gray'),
                TextEntry::make('verified')->label(__('labels.verified'))->state($verifiedAt ?? 'not verified yet')
                    ->badge()->color($verifiedAt ? 'success' : 'warning'),
                TextEntry::make('drill')->label(__('labels.restore_drill'))
                    ->state($health['latest_restore_test_at'] ? \Illuminate\Support\Str::limit($health['latest_restore_test_at'], 16).' · passed' : 'never tested')
                    ->badge()->color($health['latest_restore_test_at'] ? 'success' : 'warning'),
                TextEntry::make('retention')->label(__('labels.retention'))->state($this->retentionLine($stats)),
                TextEntry::make('db_size')->label(__('labels.live_db_size'))->state($this->bytes($stats['db_bytes'])),
            ])->headerActions([
                Action::make('trigger_backup')->label(__('labels.trigger_backup_now'))
                    ->icon('heroicon-o-archive-box')
                    ->requiresConfirmation()
                    ->modalDescription(__('labels.runs_pg_dump_for_this_project_database_r'))
                    ->action(function () {
                        try {
                            $record = ProjectBackupService::for($this->project())->trigger();
                        } catch (\Throwable $e) {
                            Notification::make()->title(__('labels.backup_failed'))->body($e->getMessage())->danger()->send();

                            return;
                        }
                        $this->audit('BACKUP_TRIGGERED', 'backup', $record->id, ['file' => basename((string) $record->log)]);
                        Notification::make()->title(__('labels.backup_complete'))->body(number_format($record->size_bytes).' bytes')->success()->send();
                    }),
            ])->compact(),
            \Filament\Schemas\Components\Html::make('<details class="cp-details"><summary>Restore policy · why there is no restore button</summary>'
                .'<p>One-click restore to the live database is deliberately unavailable: restoring needs CREATEDB-level privileges that no web request holds '
                .'(least privilege), and same-DB restore would destroy live data. The Phase 24 restore DRILL closes the testability gap: it restores into a NEW '
                .'disposable <code>restore_drill_*</code> database, validates table counts, then drops it — the active DB is never touched. '
                .'Backups are integrity-verified (checksum + archive listing) from this page.</p></details>'),
            Section::make('Backup health — <span class="cp-badge '.$healthBadge.'">'.e($health['overall']).'</span>')->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Policy</th><th>Scope</th><th>Schedule</th><th>Retention</th><th>Backup</th><th>Restore test</th><th>Last run</th></tr></thead><tbody>'
                    .$policyRows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">Offsite configured: '.($health['offsite_configured'] ? 'yes (s3-compatible destination registered)' : 'no — local only').'.</p>'
                ),
            ])->compact(),
            Section::make('Restore drills')->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Run</th><th>Status</th><th>Finished</th><th>Result</th><th>Error</th></tr></thead><tbody>'
                    .$drillRows.'</tbody></table></div>'
                ),
            ])->compact(),
            Section::make('Offsite destinations')->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Name</th><th>Driver</th><th>Endpoint</th><th>Key</th></tr></thead><tbody>'
                    .$destRows.'</tbody></table></div>'
                ),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();

        return [
            Action::make('new_policy')->label(__('labels.new_policy'))->icon('heroicon-o-plus')
                ->visible(fn () => \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'backups.policy'))
                ->schema([
                    \Filament\Forms\Components\TextInput::make('name')->required(),
                    \Filament\Forms\Components\Select::make('scope')->options(['database' => 'database', 'storage' => 'storage'])->default('database'),
                    \Filament\Forms\Components\Select::make('schedule')->options(['hourly' => 'hourly', 'daily' => 'daily', 'weekly' => 'weekly'])->default('daily'),
                    \Filament\Forms\Components\TextInput::make('retention_days')->numeric()->default(14),
                    \Filament\Forms\Components\Select::make('destination_id')->label(__('labels.destination'))
                        ->options(BackupDestination::where('project_id', $project->id)->pluck('name', 'id')->all())->native(false),
                    \Filament\Forms\Components\Toggle::make('encrypted')->default(false),
                ])
                ->action(function (array $data) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'backups.policy');
                    BackupCenterService::createPolicy($this->project(), $data);
                    Notification::make()->title(__('labels.backup_policy_created'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('new_destination')->label(__('labels.new_destination'))->icon('heroicon-o-cloud-arrow-up')
                ->visible(fn () => \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'backups.policy'))
                ->schema([
                    \Filament\Forms\Components\TextInput::make('name')->required(),
                    \Filament\Forms\Components\Select::make('driver')->options(['local' => 'local', 's3-compatible' => 's3-compatible (R2/B2/MinIO/S3)'])->default('local'),
                    \Filament\Forms\Components\TextInput::make('endpoint')->url()->placeholder('https://<account>.r2.cloudflarestorage.com'),
                    \Filament\Forms\Components\TextInput::make('bucket'),
                    \Filament\Forms\Components\TextInput::make('secret_ref')->label(__('labels.access_key_vault_secret_name'))
                        ->helperText(__('labels.vault_reference_only_never_paste_the_key')),
                ])
                ->action(function (array $data) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'backups.policy');
                    BackupCenterService::createDestination($this->project(), [
                        'name' => $data['name'],
                        'driver' => $data['driver'],
                        'config' => array_filter(['endpoint' => $data['endpoint'] ?? null, 'bucket' => $data['bucket'] ?? null]),
                        'secret_ref' => $data['secret_ref'] ?? null,
                    ]);
                    Notification::make()->title(__('labels.destination_registered'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('run_policy')->label(__('labels.run_policy_now'))->icon('heroicon-o-play')
                ->visible(fn () => \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'backups.policy') && BackupPolicy::where('project_id', $project->id)->exists())
                ->schema([
                    \Filament\Forms\Components\Select::make('policy_id')->required()->options(
                        BackupPolicy::where('project_id', $project->id)->pluck('name', 'id')->all()
                    ),
                ])
                ->action(function (array $data) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'backups.policy');
                    $policy = BackupPolicy::where('project_id', $this->project()->id)->findOrFail($data['policy_id']);
                    $run = BackupCenterService::runBackup($policy, 'manual');
                    Notification::make()->title(__('labels.policy_run_frag').$run->status)->success($run->status === 'completed')->danger($run->status === 'failed')->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            Action::make('restore_drill')->label(__('labels.restore_drill'))->icon('heroicon-o-lifebuoy')->color('warning')
                ->visible(fn () => \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'restore.local'))
                ->requiresConfirmation()
                ->modalDescription(__('labels.restores_a_completed_backup_into_a_new_d'))
                ->schema([
                    \Filament\Forms\Components\Select::make('backup_id')->required()->options(
                        BackupRun::where('project_id', $project->id)->where('type', 'database')->where('status', 'completed')
                            ->orderByDesc('id')->limit(20)->pluck('id', 'id')->all()
                    )->helperText(__('labels.completed_database_backup_runs')),
                ])
                ->action(function (array $data) {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'restore.local');
                    $backup = BackupRun::where('project_id', $this->project()->id)->findOrFail($data['backup_id']);
                    $drill = BackupCenterService::requestRestoreDrill($backup);
                    Notification::make()->title(__('labels.drill_frag').$drill->status)
                        ->body($drill->meta['tables_restored'] ?? $drill->error ?? '')
                        ->success($drill->status === 'drill_passed')->danger($drill->status === 'drill_failed')->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }

    protected function retentionLine(array $stats): string
    {
        /** @var list<array{file:string,size:int,modified:string}> $files */
        $files = $stats['files'] ?? [];
        $old = array_filter($files, fn ($f) => strtotime($f['modified']) < time() - 30 * 86400);
        $total = array_sum(array_column($files, 'size'));

        return count($files).' local dumps · '.$this->bytes((int) $total)
            .' · '.count($old).' older than 30 days (prune from host/backups/data; offsite target still pending per Phase 17).';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BackupRecord::where('db_name', $this->project()->db_name)->orderByDesc('finished_at'))
            ->columns([
                TextColumn::make('finished_at')->label(__('labels.created'))->dateTime()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge()->color(fn ($s) => $s === 'ok' ? 'success' : 'danger'),
                TextColumn::make('size_bytes')->label(__('labels.size'))->formatStateUsing(fn ($s) => $this->bytes((int) $s)),
                TextColumn::make('verified_at')->label(__('labels.verified'))->dateTime()->placeholder('—'),
                TextColumn::make('restore_test_status')->label(__('labels.restore_test'))->badge(),
                TextColumn::make('location')->badge()->color('gray'),
            ])
            ->recordActions([
                Action::make('verify')->label(__('labels.verify'))->icon('heroicon-o-check-badge')
                    ->visible(fn ($record) => $record->verified_at === null)
                    ->requiresConfirmation()
                    ->action(function (BackupRecord $record) {
                        try {
                            ProjectBackupService::for($this->project())->verify($record);
                        } catch (\Throwable $e) {
                            Notification::make()->title(__('labels.verification_failed'))->body($e->getMessage())->danger()->send();

                            return;
                        }
                        $this->audit('BACKUP_VERIFIED', 'backup', $record->id);
                        Notification::make()->title(__('labels.backup_verified'))->success()->send();
                    }),
            ])
            ->emptyStateHeading('No backups recorded for this project yet');
    }

    protected function bytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) {
            if ($b < 1024) {
                return round($b, 1).' '.$u;
            }
            $b /= 1024;
        }

        return round($b, 1).' TB';
    }
}
