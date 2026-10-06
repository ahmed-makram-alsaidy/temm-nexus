<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\HorizonStatsReader;
use App\Services\ControlPlane\ProjectArtisan;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Queue overview: Horizon supervisor state (when it ran), per-queue pending
 * depths, and the failed-jobs table with audited retry/forget via the
 * project's own `queue:retry` / `queue:forget` commands.
 */
class ProjectQueues extends Page implements HasTable
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
        return __('labels.queues');
    }

    public function getBreadcrumbs(): array
    {
        return ['Queues'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('queues'),
            EmbeddedSchema::make('infolist'),
            EmbeddedTable::make(),
        ]);
    }

    public function infolist(Schema $schema): Schema
    {
        $stats = HorizonStatsReader::for($this->project())->stats();
        $depth = array_sum(array_column($stats['queues'], 'pending'));
        $queueRows = '';
        foreach ($stats['queues'] as $q) {
            $queueRows .= '<tr><td><code>'.e($q['queue']).'</code></td><td class="cp-num">'.(int) $q['pending'].'</td></tr>';
        }
        $queuesHtml = $queueRows !== ''
            ? '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_queue')).'</th><th class="cp-num">'.e(__('labels.qu_pending')).'</th></tr></thead><tbody>'.$queueRows.'</tbody></table></div>'
            : '<div class="cp-empty"><div class="cp-empty__icon">∅</div>'
                .'<div class="cp-empty__title">'.e(__('labels.qu_empty_title')).'</div>'
                .'<div class="cp-empty__hint">'.e(__('labels.qu_empty_body')).'</div></div>';

        $healthLine = '<span class="cp-badge '.($stats['running'] ? 'is-success' : '').'">'
            .'Horizon · '.($stats['running'] ? e(__('labels.qu_horizon_seen')) : e(__('labels.qu_horizon_not_running'))).'</span> '
            .'<span class="cp-badge '.((int) $stats['failed'] > 0 ? 'is-danger' : 'is-success').'">'
            .e(__('labels.qu_failed_count', ['count' => (int) $stats['failed']])).'</span> '
            .'<span class="cp-badge">'.e(__('labels.qu_pending_count', ['count' => (int) $depth])).'</span> '
            .'<span class="cp-badge">'.e(__('labels.qu_processed_count', ['count' => (int) $stats['processed']])).'</span>';

        return $schema->components([
            Section::make(__('labels.qu_health'))->schema([
                Html::make('<div class="cp-toolbar" style="margin-bottom:.5rem">'.$healthLine.'</div>'
                    .'<p class="cp-healthline__sub">'.e(__('labels.qu_counters_note')).'</p>'
                    .'<details class="cp-details"><summary>'.e(__('labels.qu_horizon_detail')).'</summary><p>'
                    .($stats['supervisors'] === [] ? e(__('labels.qu_no_supervisors')) : e(__('labels.qu_supervisors', ['count' => count($stats['supervisors'])])))
                    .'</p></details>'),
            ])->compact(),
            Section::make(__('labels.qu_queues_count', ['count' => count($stats['queues'])]))->schema([
                Html::make($queuesHtml),
            ])->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        try {
            $conn = ProjectConnectionManager::connection($this->project());
            $hasFailed = DB::connection($conn)->getSchemaBuilder()->hasTable('failed_jobs');
        } catch (\Throwable) {
            $hasFailed = false;
        }

        if (! $hasFailed) {
            return $this->emptyTable($table, __('labels.qu_no_failed_table'), __('labels.qu_no_failed_table_body'));
        }

        $failed = \App\Models\ProjectRecord::onTable($conn, 'failed_jobs', 'id');

        return $table
            ->query(fn () => $failed->newQuery()->orderByDesc('failed_at'))
            ->columns([
                // Internal PK + raw payload are implementation detail: hidden
                // by default, reachable through the column manager (H5).
                TextColumn::make('id')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('queue')->badge()->searchable(),
                TextColumn::make('payload')->limit(60)
                    ->formatStateUsing(fn ($s) => is_string($s) ? mb_substr($s, 0, 60) : '—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('exception')->limit(80)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failed_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('retry')->label(__('labels.retry'))->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // queue:retry addresses jobs by UUID, not numeric id.
                        $result = ProjectArtisan::run($this->project(), 'queue:retry', [(string) $record->uuid]);
                        if (! $result['ok']) {
                            // Raw command output stays in the log (H3).
                            report(new \RuntimeException('queue:retry failed: '.$result['output']));
                            abort(422, __('labels.qu_retry_failed'));
                        }
                        $this->audit('QUEUE_JOB_RETRIED', 'failed_job', $record->uuid);
                        Notification::make()->title(__('labels.job_pushed_back_to_the_queue'))->success()->send();
                    }),
                Action::make('forget')->label(__('labels.delete'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $result = ProjectArtisan::run($this->project(), 'queue:forget', [(string) $record->uuid]);
                        if (! $result['ok']) {
                            report(new \RuntimeException('queue:forget failed: '.$result['output']));
                            abort(422, __('labels.qu_delete_failed'));
                        }
                        $this->audit('QUEUE_JOB_DELETED', 'failed_job', $record->uuid);
                        Notification::make()->title(__('labels.failed_job_deleted'))->success()->send();
                    }),
            ])
            ->heading(__('labels.qu_failed_jobs'))
            ->emptyStateHeading(__('labels.qu_no_failed_jobs'))
            ->emptyStateDescription(__('labels.qu_no_failed_jobs_body'))
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
