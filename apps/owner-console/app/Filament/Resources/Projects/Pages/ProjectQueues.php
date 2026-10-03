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
            ? '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Queue</th><th class="cp-num">Pending</th></tr></thead><tbody>'.$queueRows.'</tbody></table></div>'
            : '<div class="cp-empty"><div class="cp-empty__icon">∅</div>'
                .'<div class="cp-empty__title">No queued work</div>'
                .'<div class="cp-empty__hint">All project queues are empty right now.</div></div>';

        $healthLine = '<span class="cp-badge '.($stats['running'] ? 'is-success' : '').'">'
            .'Horizon · '.($stats['running'] ? 'seen recently' : 'not running').'</span> '
            .'<span class="cp-badge '.((int) $stats['failed'] > 0 ? 'is-danger' : 'is-success').'">'
            .(int) $stats['failed'].' failed</span> '
            .'<span class="cp-badge">'.(int) $depth.' pending</span> '
            .'<span class="cp-badge">'.(int) $stats['processed'].' processed</span>';

        return $schema->components([
            Section::make('Queue health')->schema([
                Html::make('<div class="cp-toolbar" style="margin-bottom:.5rem">'.$healthLine.'</div>'
                    .'<p class="cp-healthline__sub">Counters come from the Horizon Redis namespace when Horizon ran; failed count always comes from failed_jobs.</p>'
                    .'<details class="cp-details"><summary>Horizon detail</summary><p>'
                    .($stats['supervisors'] === [] ? 'No supervisors observed.' : count($stats['supervisors']).' supervisor(s) observed.')
                    .'</p></details>'),
            ])->compact(),
            Section::make('Queues · '.count($stats['queues']))->schema([
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
            return $this->emptyTable($table, 'No failed-jobs table', 'The project database is unreachable or has no failed_jobs table.');
        }

        $failed = \App\Models\ProjectRecord::onTable($conn, 'failed_jobs', 'id');

        return $table
            ->query(fn () => $failed->newQuery()->orderByDesc('failed_at'))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('queue')->badge()->searchable(),
                TextColumn::make('payload')->limit(60)->formatStateUsing(fn ($s) => is_string($s) ? mb_substr($s, 0, 60) : '—'),
                TextColumn::make('exception')->limit(80)->toggleable(),
                TextColumn::make('failed_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('retry')->label(__('labels.retry'))->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // queue:retry addresses jobs by UUID, not numeric id.
                        $result = ProjectArtisan::run($this->project(), 'queue:retry', [(string) $record->uuid]);
                        abort_unless($result['ok'], 422, 'Retry failed: '.$result['output']);
                        $this->audit('QUEUE_JOB_RETRIED', 'failed_job', $record->uuid);
                        Notification::make()->title(__('labels.job_pushed_back_to_the_queue'))->success()->send();
                    }),
                Action::make('forget')->label(__('labels.delete'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $result = ProjectArtisan::run($this->project(), 'queue:forget', [(string) $record->uuid]);
                        abort_unless($result['ok'], 422, 'Delete failed: '.$result['output']);
                        $this->audit('QUEUE_JOB_DELETED', 'failed_job', $record->uuid);
                        Notification::make()->title(__('labels.failed_job_deleted'))->success()->send();
                    }),
            ])
            ->heading('Failed jobs')
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('Failures land here with queue, payload and exception — retry or delete with audit trail.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
