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
use App\Support\ProductStatus;
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
            $drillRows .= '<tr><td>#'.$drill->id.'</td><td><span class="cp-badge '.$badge.'">'.e(ProductStatus::label((string) $drill->status)).'</span></td>'
                .'<td>'.e($drill->finished_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? __('labels.bk_drill_running')).'</td>'
                .'<td style="font-size:.75rem">'.e(__('labels.bk_drill_tables', ['count' => (int) ($drill->meta['tables_restored'] ?? 0)])) .'</td>'
                .'<td style="font-size:.75rem"><code dir="ltr">'.e(\Illuminate\Support\Str::limit((string) $drill->error, 80)).'</code></td></tr>';
        }
        if ($drillRows === '') {
            $drillRows = '<tr><td colspan="5">'.e(__('labels.bk_drills_empty')).'</td></tr>';
        }
        $destinations = BackupDestination::where('project_id', $project->id)->orderBy('id')->get();
        $destRows = '';
        foreach ($destinations as $dest) {
            $destRows .= '<tr><td><strong>'.e($dest->name).'</strong></td><td><code dir="ltr">'.e($dest->driver).'</code></td>'
                .'<td style="font-size:.75rem"><code dir="ltr">'.e(($dest->config['endpoint'] ?? '')).($dest->config['bucket'] ?? '' ? ' / '.e($dest->config['bucket']) : '').'</code></td>'
                .'<td>'.($dest->secret_ref ? '<code dir="ltr">'.e($dest->secret_ref).'</code>' : '<span class="cp-badge">'.e(__('labels.bk_no_offsite_key')).'</span>').'</td></tr>';
        }
        if ($destRows === '') {
            $destRows = '<tr><td colspan="4">'.e(__('labels.bk_destinations_empty')).'</td></tr>';
        }

        return $schema->components([
            Section::make(__('labels.bk_status'))->schema([
                TextEntry::make('last')->label(__('labels.latest_backup'))->state($stats['last_backup']
                    ? $stats['last_backup']->finished_at?->locale(app()->getLocale())->translatedFormat('M j, H:i').' · '.ProductStatus::label((string) $stats['last_backup']->status)
                    : __('labels.bk_none_yet'))
                    ->badge()->color($stats['last_backup'] ? 'success' : 'gray'),
                TextEntry::make('verified')->label(__('labels.verified'))->state($verifiedAt ?? __('labels.bk_not_verified_yet'))
                    ->badge()->color($verifiedAt ? 'success' : 'warning'),
                TextEntry::make('drill')->label(__('labels.restore_drill'))
                    ->state($health['latest_restore_test_at'] ? \Illuminate\Support\Str::limit($health['latest_restore_test_at'], 16).' · '.ProductStatus::label('drill_passed') : __('labels.bk_never_tested'))
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
                            // The raw message stays in the logs (report())
                            // and the audit ledger — the notification speaks
                            // the product error pattern instead.
                            report($e);
                            Notification::make()->title(__('labels.backup_failed'))
                                ->body(__('labels.backup_failed_body'))->danger()->send();

                            return;
                        }
                        $this->audit('BACKUP_TRIGGERED', 'backup', $record->id, ['file' => basename((string) $record->log)]);
                        Notification::make()->title(__('labels.backup_complete'))->body(__('labels.bk_backup_size', ['size' => $this->bytes((int) $record->size_bytes)]))->success()->send();
                    }),
            ])->compact(),
            \Filament\Schemas\Components\Html::make('<details class="cp-details"><summary>'.e(__('labels.bk_policy_summary')).'</summary>'
                .'<p>'.e(__('labels.bk_policy_body')).'</p>'
                .'<p><code dir="ltr">restore_drill_*</code> — '.e(__('labels.bk_policy_drill_note')).'</p></details>'),
            Section::make(__('labels.bk_health_title').' — <span class="cp-badge '.$healthBadge.'">'.e(ProductStatus::label((string) $health['overall'])).'</span>')->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_policy')).'</th><th>'.e(__('labels.th_scope')).'</th><th>'.e(__('labels.th_schedule')).'</th><th>'.e(__('labels.retention')).'</th><th>'.e(__('labels.th_backup')).'</th><th>'.e(__('labels.restore_test')).'</th><th>'.e(__('labels.th_last_run')).'</th></tr></thead><tbody>'
                    .$policyRows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim);margin-top:.3rem">'.e(__('labels.bk_offsite_configured', [
                        'state' => $health['offsite_configured'] ? __('labels.bk_offsite_yes') : __('labels.bk_offsite_no'),
                    ])).'</p>'
                ),
            ])->compact(),
            Section::make(__('labels.bk_drills_title'))->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.run')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_finished')).'</th><th>'.e(__('labels.th_result')).'</th><th>'.e(__('labels.th_error')).'</th></tr></thead><tbody>'
                    .$drillRows.'</tbody></table></div>'
                ),
            ])->compact(),
            Section::make(__('labels.bk_destinations_title'))->schema([
                \Filament\Schemas\Components\Html::make(
                    '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.th_driver')).'</th><th>'.e(__('labels.endpoint')).'</th><th>'.e(__('labels.key')).'</th></tr></thead><tbody>'
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
                    Notification::make()->title(__('labels.bk_policy_run', ['status' => ProductStatus::label((string) $run->status)]))
                        ->success($run->status === 'completed')->danger($run->status === 'failed')->send();
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
                    Notification::make()->title(__('labels.bk_drill_result', ['status' => ProductStatus::label((string) $drill->status)]))
                        ->body($drill->status === 'drill_passed'
                            ? __('labels.bk_drill_passed_body', ['tables' => (int) ($drill->meta['tables_restored'] ?? 0)])
                            : __('labels.bk_drill_failed_body'))
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

        return __('labels.bk_retention_line', [
            'count' => count($files),
            'size' => $this->bytes((int) $total),
            'old' => count($old),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BackupRecord::where('db_name', $this->project()->db_name)->orderByDesc('finished_at'))
            ->columns([
                TextColumn::make('finished_at')->label(__('labels.created'))->dateTime()->sortable(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($s) => \Illuminate\Support\Facades\Lang::has('labels.bk_type_'.(string) $s, app()->getLocale())
                        ? __('labels.bk_type_'.(string) $s) : (string) $s),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($s) => ProductStatus::label((string) $s))
                    ->color(fn ($s) => ProductStatus::color((string) $s)),
                TextColumn::make('size_bytes')->label(__('labels.size'))->formatStateUsing(fn ($s) => $this->bytes((int) $s)),
                TextColumn::make('verified_at')->label(__('labels.verified'))->dateTime()->placeholder('—'),
                TextColumn::make('restore_test_status')->label(__('labels.restore_test'))->badge()
                    ->formatStateUsing(fn ($s) => $s === null ? '—' : ProductStatus::label((string) $s))
                    ->color(fn ($s) => ProductStatus::color((string) $s)),
                TextColumn::make('location')->badge()->color('gray')
                    ->formatStateUsing(fn ($s) => \Illuminate\Support\Facades\Lang::has('labels.bk_location_'.(string) $s, app()->getLocale())
                        ? __('labels.bk_location_'.(string) $s) : (string) $s),
            ])
            ->recordActions([
                Action::make('verify')->label(__('labels.verify'))->icon('heroicon-o-check-badge')
                    ->visible(fn ($record) => $record->verified_at === null)
                    ->requiresConfirmation()
                ->action(function (BackupRecord $record) {
                    try {
                        ProjectBackupService::for($this->project())->verify($record);
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->title(__('labels.verification_failed'))
                            ->body(__('labels.bk_verify_failed_body'))->danger()->send();

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
