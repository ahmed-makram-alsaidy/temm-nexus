<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\PulseReader;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Application monitoring from the project's Pulse tables plus infra counters.
 * The full Pulse dashboard remains available at /pulse (owner-only gate).
 */
class ProjectMonitoring extends Page
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
        return __('labels.monitoring');
    }

    public function getBreadcrumbs(): array
    {
        return ['Monitoring'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('monitoring'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $pulse = PulseReader::for($this->project());
        $present = $pulse->tablesPresent();
        $counts = $present ? $pulse->entryCounts() : [];
        $exceptions = $present ? $pulse->recent('exception', 10) : [];
        $slowQueries = $present ? $pulse->recent('slow_query', 25) : [];
        $slowRequests = $present ? $pulse->recent('slow_request', 50) : [];

        $total = array_sum($counts);
        $countBadges = '';
        foreach ($counts as $type => $c) {
            $countBadges .= '<span class="cp-badge">'.e($type).' · '.(int) $c.'</span> ';
        }
        $stateLine = $present
            ? 'Recording · '.(int) $total.' entries'.($countBadges !== '' ? '<br><span style="display:inline-flex;gap:.25rem;flex-wrap:wrap;margin-top:.375rem">'.$countBadges.'</span>' : '')
            : 'Pulse tables missing or never ran in this project. Full dashboard: /pulse (owner only).';

        return $schema->components([
            // Hierarchy strip (Phase 20.7): System · Application · Database · Queues · Realtime.
            Html::make('<div class="cp-toolbar" aria-label="Monitoring hierarchy">'
                .'<a class="cp-qa__btn" href="'.e(static::projectUrl($this->project(), 'db-health')).'">System · Health</a>'
                .'<a class="cp-qa__btn" href="'.e(static::projectUrl($this->project(), 'monitoring')).'" aria-current="true">Application · Pulse</a>'
                .'<a class="cp-qa__btn" href="'.e(static::projectUrl($this->project(), 'database')).'">Database</a>'
                .'<a class="cp-qa__btn" href="'.e(static::projectUrl($this->project(), 'queues')).'">Queues</a>'
                .'<a class="cp-qa__btn" href="'.e(static::projectUrl($this->project(), 'realtime')).'">Realtime</a>'
                .'</div>'),
            Section::make('Application health')->schema([
                TextEntry::make('state')->state(new \Illuminate\Support\HtmlString($stateLine)),
            ])->compact(),
            Section::make('Slow requests'.($slowRequests === [] ? '' : ' · '.count($this->groupSlowRequests($slowRequests)).' endpoints'))->schema([
                Html::make($this->slowRequestsHtml($slowRequests, $present)),
            ])->compact(),
            Grid::make(2)->schema([
                Section::make('Slow queries ('.count($slowQueries).')')->schema([
                    Html::make($this->slowQueriesHtml($slowQueries, $present)),
                ])->compact(),
                Section::make('Exceptions ('.count($exceptions).')')->schema([
                    Html::make($this->exceptionsHtml($exceptions, $present)),
                ])->compact(),
            ]),
        ]);
    }

    /** @param list<array{type:string,key:string,value:string,timestamp:string|int}> $rows */
    protected function groupSlowRequests(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            [$method, $endpoint] = self::parseSlowRequestKey((string) ($r['key'] ?? ''));
            $key = $method.' '.$endpoint;
            $groups[$key] ??= ['endpoint' => $endpoint, 'method' => $method, 'durations' => [], 'latest' => 0];
            $groups[$key]['durations'][] = (float) ($r['value'] ?? 0);
            $ts = is_numeric($r['timestamp'] ?? null) ? (int) $r['timestamp'] : strtotime((string) ($r['timestamp'] ?? ''));
            $groups[$key]['latest'] = max($groups[$key]['latest'], $ts ?: 0);
        }
        foreach ($groups as &$g) {
            sort($g['durations']);
            $n = count($g['durations']);
            $g['count'] = $n;
            $g['avg'] = $n ? array_sum($g['durations']) / $n : 0;
            $g['p95'] = $n ? $g['durations'][min($n - 1, (int) ceil(0.95 * $n) - 1)] : 0;
        }
        unset($g);
        uasort($groups, fn ($a, $b) => $b['p95'] <=> $a['p95']);

        return $groups;
    }

    /** @return array{0:string,1:string} method + endpoint */
    protected static function parseSlowRequestKey(string $key): array
    {
        $decoded = json_decode($key, true);
        if (is_array($decoded) && isset($decoded[0], $decoded[1])) {
            return [(string) $decoded[0], (string) $decoded[1]];
        }

        return ['—', $key !== '' ? $key : '—'];
    }

    protected static function fmtTs(int $ts): string
    {
        return $ts > 0 ? date('M j, H:i:s', $ts) : '—';
    }

    /** @param list<array> $rows */
    protected function slowRequestsHtml(array $rows, bool $present): string
    {
        if (! $present) {
            return '<div class="cp-empty"><div class="cp-empty__icon">∿</div>'
                .'<div class="cp-empty__title">No Pulse data</div>'
                .'<div class="cp-empty__hint">Install Pulse in the project and let it record traffic.</div></div>';
        }
        $groups = $this->groupSlowRequests($rows);
        if ($groups === []) {
            return '<div class="cp-empty"><div class="cp-empty__icon">✓</div>'
                .'<div class="cp-empty__title">No slow requests</div>'
                .'<div class="cp-empty__hint">Nothing over the slow-request threshold in the recorded window.</div></div>';
        }
        $tr = '';
        foreach ($groups as $g) {
            $tr .= '<tr><td><code>'.e($g['endpoint']).'</code></td>'
                .'<td class="cp-num">'.(int) $g['count'].'</td>'
                .'<td class="cp-num">'.number_format($g['avg'], 0).' ms</td>'
                .'<td class="cp-num">'.number_format($g['p95'], 0).' ms</td>'
                .'<td style="white-space:nowrap">'.e(self::fmtTs($g['latest'])).'</td></tr>';
        }

        return '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.e(__('labels.endpoint')).'</th><th class="cp-num">Count</th><th class="cp-num">Avg</th>'
            .'<th class="cp-num">p95</th>.'.e(__('labels.')).'</th><th>'
            .'</tr></thead><tbody>'.$tr.'</tbody></table></div>';
    }

    /** @param list<array> $rows */
    protected function slowQueriesHtml(array $rows, bool $present): string
    {
        if (! $present) {
            return '<div class="cp-empty"><div class="cp-empty__icon">∿</div>'
                .'<div class="cp-empty__title">No Pulse data</div></div>';
        }
        if ($rows === []) {
            return '<div class="cp-empty"><div class="cp-empty__icon">✓</div>'
                .'<div class="cp-empty__title">No slow queries</div>'
                .'<div class="cp-empty__hint">Recorded query durations are all under threshold.</div></div>';
        }
        $tr = '';
        foreach (array_slice($rows, 0, 10) as $r) {
            $ts = is_numeric($r['timestamp'] ?? null) ? (int) $r['timestamp'] : strtotime((string) ($r['timestamp'] ?? ''));
            $tr .= '<tr><td><code>'.e(mb_substr((string) ($r['key'] ?? ''), 0, 90)).'</code></td>'
                .'<td class="cp-num" style="white-space:nowrap">'.e(mb_substr((string) ($r['value'] ?? ''), 0, 24)).'</td>'
                .'<td style="white-space:nowrap">'.e(self::fmtTs($ts ?: 0)).'</td></tr>';
        }

        return '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.e(__('labels.th_query')).'</th><th class="cp-num">Duration</th>.'.e(__('labels.')).'</th><th>'
            .'</tr></thead><tbody>'.$tr.'</tbody></table></div>';
    }

    /** @param list<array> $rows */
    protected function exceptionsHtml(array $rows, bool $present): string
    {
        if (! $present) {
            return '<div class="cp-empty"><div class="cp-empty__icon">∿</div>'
                .'<div class="cp-empty__title">No Pulse data</div></div>';
        }
        if ($rows === []) {
            return '<div class="cp-empty"><div class="cp-empty__icon">✓</div>'
                .'<div class="cp-empty__title">No exceptions recorded</div>'
                .'<div class="cp-empty__hint">The recorded window is clean. New exceptions appear here with class and time.</div></div>';
        }
        $tr = '';
        foreach ($rows as $r) {
            $ts = is_numeric($r['timestamp'] ?? null) ? (int) $r['timestamp'] : strtotime((string) ($r['timestamp'] ?? ''));
            $tr .= '<tr><td><code>'.e(mb_substr((string) ($r['key'] ?? ''), 0, 90)).'</code></td>'
                .'<td style="white-space:nowrap">'.e(self::fmtTs($ts ?: 0)).'</td></tr>';
        }

        return '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.e(__('labels.th_exception')).'</th>.'.e(__('labels.')).'</th><th>'
            .'</tr></thead><tbody>'.$tr.'</tbody></table></div>';
    }
}
