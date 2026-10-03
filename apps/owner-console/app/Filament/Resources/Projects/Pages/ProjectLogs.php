<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\LogExplorerService;
use App\Services\ControlPlane\ProjectArtisan;
use App\Services\ControlPlane\ProjectLogReader;
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
use Livewire\Attributes\Url;

/**
 * Phase 20Q unified Logs Explorer: laravel files + audit/auth + functions +
 * webhooks + scheduler + realtime + SQL + backups + queue, filterable by
 * source, severity, text and request ID (correlation), with a detail drawer
 * section. All display text is sanitized; secrets never surface.
 */
class ProjectLogs extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'source')]
    public ?string $source = null;

    #[Url(as: 'severity')]
    public ?string $severity = null;

    #[Url(as: 'q')]
    public ?string $search = null;

    #[Url(as: 'request_id')]
    public ?string $requestId = null;

    #[Url(as: 'since')]
    public ?string $since = null;

    #[Url(as: 'show')]
    public ?string $show = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->source ??= request()->query('source');
        $this->severity ??= request()->query('severity');
        $this->search ??= request()->query('q');
        $this->requestId ??= request()->query('request_id');
        $this->since ??= request()->query('since');
        $this->show ??= request()->query('show');
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.logs_explorer');
    }

    public function getBreadcrumbs(): array
    {
        return [__('labels.logs_explorer')];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'logs.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('logs'), EmbeddedSchema::make('infolist')]);
    }

    protected function self(array $extra = []): string
    {
        return static::getUrl(array_merge(
            ['record' => $this->project(), 'source' => $this->source, 'severity' => $this->severity,
                'q' => $this->search, 'request_id' => $this->requestId, 'since' => $this->since],
            $extra
        ));
    }

    public function infolist(Schema $schema): Schema
    {
        $result = LogExplorerService::query($this->project(), [
            'source' => $this->source, 'severity' => $this->severity,
            'q' => $this->search, 'request_id' => $this->requestId, 'since' => $this->since, 'limit' => 100,
        ]);
        $oldestError = null;
        foreach ($result['rows'] as $r) {
            if (! in_array($r['severity'], ['error', 'critical', 'alert', 'emergency'], true)) {
                continue;
            }
            $ts = strtotime((string) ($r['time'] ?? ''));
            if ($ts !== false && ($oldestError === null || $ts < $oldestError)) {
                $oldestError = $ts;
            }
        }
        $historyNote = ($oldestError !== null && $oldestError < time() - 7 * 86400)
            ? '<div class="cp-reference__notice"><strong>Historical errors in view</strong>'
                .'<p>Oldest error shown: '.e(date('M j, Y H:i', $oldestError)).'. History is preserved with timestamps — '
                .'verify a fix in the current runtime before treating an old entry as resolved.</p></div>'
            : '';
        $reader = ProjectLogReader::for($this->project());
        $files = count($reader->files());

        $rows = '';
        foreach ($result['rows'] as $r) {
            $sev = $r['severity'];
            $badge = in_array($sev, ['error', 'critical', 'alert', 'emergency'], true) ? 'is-danger'
                : (in_array($sev, ['warning'], true) ? 'is-warning' : 'is-info');
            $detail = $this->self(['show' => $r['ref']]);
            $rows .= '<tr><td style="white-space:nowrap">'.e($r['time'] ? mb_substr($r['time'], 5, 14) : '—').'</td>'
                .'<td>'.e($r['source']).'</td>'
                .'<td><span class="cp-badge '.$badge.'">'.e($sev).'</span></td>'
                .'<td>'.e(mb_substr($r['summary'], 0, 220)).'</td>'
                .'<td>'.($r['request_id'] ? '<a href="'.e($this->self(['request_id' => $r['request_id'], 'show' => null])).'"><code>'.e(mb_substr($r['request_id'], 0, 8)).'…</code></a>' : '—').'</td>'
                .'<td><a href="'.e($detail).'">Detail →</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">No log entries match. Try clearing filters'
                .($files === 0 ? ' (note: this project has no Laravel log files on this host — checkout not deployed)' : '')
                .'.</td></tr>';
        }
        $filterChips = [];
        foreach (['source' => $this->source, 'severity' => $this->severity, 'q' => $this->search, 'request_id' => $this->requestId, 'since' => $this->since] as $k => $v) {
            if ($v) {
                $filterChips[] = '<span class="cp-badge is-info">'.e($k).': '.e(mb_substr((string) $v, 0, 40)).'</span>';
            }
        }

        $components = [
            Section::make(__('labels.logs_explorer'))->schema([
                Html::make($historyNote.'<div class="cp-toolbar">'
                    .($filterChips ? implode(' ', $filterChips).' <a href="'.e($this->self([
                        'source' => null, 'severity' => null, 'q' => null, 'request_id' => null, 'since' => null, 'show' => null,
                    ])).'">Clear ✕</a>' : '<span class="cp-toolbar__count">'.__('labels.logs_latest_100').($result['truncated'] ? __('labels.logs_truncated_suffix') : '').'</span>')
                    .'</div>'
                    .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.logs_th_time')).'</th><th>'.e(__('labels.logs_th_source')).'</th><th>'.e(__('labels.logs_th_severity')).'</th><th>'.e(__('labels.logs_th_summary')).'</th><th>'.e(__('labels.logs_th_request')).'</th><th></th>'
                    .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('labels.logs_sources_note')).'</p>'
                   ),
            ])->headerActions([
                Action::make('filter')->label(__('labels.filter'))->icon('heroicon-o-funnel')
                    ->schema([
                        Select::make('source')->options(['all' => __('labels.all_sources')] + array_combine(LogExplorerService::SOURCES, LogExplorerService::SOURCES))
                            ->default($this->source ?? 'all'),
                        Select::make('severity')->options([
                            'all' => __('labels.all_severities'), 'debug' => __('labels.debug'), 'info' => __('labels.info'),
                            'warning' => __('labels.warning'), 'error' => __('labels.error'),
                        ])->default($this->severity ?? 'all'),
                        TextInput::make('q')->label(__('labels.search'))->placeholder(__('labels.summary_contains'))->default($this->search),
                        TextInput::make('request_id')->label(__('labels.request_id'))->placeholder(__('labels.uuid_ellipsis'))->default($this->requestId),
                        Select::make('since')->label(__('labels.time_range'))->options([
                            'all' => __('labels.all_time_history_preserved'), '24h' => __('labels.last_24_hours'),
                            '7d' => __('labels.last_7_days'), '30d' => __('labels.last_30_days'),
                        ])->default($this->since ?? 'all'),
                    ])
                    ->action(function (array $data) {
                        $norm = fn ($v) => ($v === 'all' || $v === '' || $v === null) ? null : $v;
                        $this->redirect($this->self([
                            'source' => $norm($data['source'] ?? null),
                            'severity' => $norm($data['severity'] ?? null),
                            'q' => $norm($data['q'] ?? null),
                            'request_id' => $norm($data['request_id'] ?? null),
                            'since' => $norm($data['since'] ?? null),
                            'show' => null,
                        ]));
                    }),
                Action::make('test_exception')->label(__('labels.generate_test_exception'))
                    ->icon('heroicon-o-bug-ant')
                    ->requiresConfirmation()
                    ->modalDescription(__('labels.runs_demo_throw_test_exception_in_the_pr'))
                    ->action(function () {
                        try {
                            $result = ProjectArtisan::run($this->project(), 'demo:throw-test-exception');
                        } catch (\Throwable $e) {
                            Notification::make()->title(__('labels.not_available'))->body($e->getMessage())->warning()->send();

                            return;
                        }
                        $this->audit('PROJECT_HEALTH_CHECKED', 'logs', 'test-exception');
                        Notification::make()->title(__('labels.test_exception_logged'))->body(mb_substr($result['output'], 0, 200))->success()->send();
                        $this->redirect($this->self(['source' => 'laravel', 'severity' => 'error']));
                    }),
            ]),
        ];

        if ($this->show) {
            $detail = LogExplorerService::detail($this->project(), $this->show);
            // Event detail renders as a right drawer over the stream (Phase 20.7).
            $close = $this->self(['show' => null]);
            $components[] = Html::make('<div class="cp-drawer" data-open="true" role="dialog" aria-label="Log event detail">'
                .'<a class="cp-drawer__close" href="'.e($close).'">Close ✕</a>'
                .'<div class="cp-drawer__head"><h3 class="cp-drawer__title">Event · '.e($this->show).'</h3></div>'
                .'<div class="cp-drawer__body">'
                .($detail === null
                    ? '<div class="cp-empty__hint">Detail unavailable (wrong project or pruned row).</div>'
                    : '<div class="cp-code">'.e(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</div>')
                .'</div></div>');
        }

        return $schema->components($components);
    }
}
