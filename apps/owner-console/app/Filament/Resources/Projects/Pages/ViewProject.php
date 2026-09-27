<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BackupRecord;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ControlPlanePaths;
use App\Services\ControlPlane\ProjectHealthService;
use App\Services\ControlPlane\ProjectOverviewData;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Redis;

class ViewProject extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    /** Breadcrumb tail: the page heading already shows the project name. */
    protected static ?string $breadcrumb = 'Overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Overview';
    }

    public function getBreadcrumbs(): array
    {
        return ['Overview'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--overview'])->components([
            $this->subnavSection('overview'),
            EmbeddedSchema::make('infolist'),
        ]);
    }

    /**
     * Operational home: context strip, health grid, usage metrics,
     * activity + needs-attention. Every source degrades to "—",
     * never to an exception.
     */
    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        $data = ProjectOverviewData::for($p);
        $health = $data['health'] + ['ok' => null, 'database' => [], 'redis' => [], 'application' => []];
        $m = $data['metrics'];
        $pulse = is_array($m['pulse'] ?? null) ? $m['pulse'] : [];
        $requests = array_sum(array_map('intval', $pulse));
        $errors = (int) ($pulse['exception'] ?? 0) + (int) ($pulse['slow_request'] ?? 0);
        /** @var BackupRecord|null $lastBackup */
        $lastBackup = $m['last_backup'];
        $B = ProjectOverviewData::class;

        $db = is_array($health['database'] ?? null) ? $health['database'] : [];
        $redis = is_array($health['redis'] ?? null) ? $health['redis'] : [];
        $failed = is_numeric($m['queue_failed'] ?? null) ? (int) $m['queue_failed'] : null;
        $pending = is_numeric($redis['pending_jobs'] ?? null) ? (int) $redis['pending_jobs'] : null;

        return $schema
            ->record($p)
            ->components([
                Html::make($this->contextHtml($p)),
                Html::make('<h3 class="cp-ov__h">Health</h3><div class="cp-ov__grid">'
                    .$this->cell('Database', ! empty($db['reachable']) ? 'Healthy' : (($health['ok'] === null && empty($db)) ? '—' : 'Unreachable'), ! empty($db['reachable']) ? ((int) ($db['connections'] ?? 0)).(((int) ($db['connections'] ?? 0)) === 1 ? ' conn' : ' conns') : '', empty($db['reachable']) && ! ($health['ok'] === null && empty($db)) ? 'is-danger' : 'is-success')
                    .$this->cell('API', $requests.' req', $errors > 0 ? $errors.' errors' : 'Pulse snapshot', $errors > 0 ? 'is-warning' : '')
                    .$this->cell('Storage', $B::bytes(is_numeric($m['storage'] ?? null) ? (int) $m['storage'] : null), '', '')
                    .$this->cell('Queues', $pending === null && $failed === null ? '—' : trim(($pending ?? 0).' pending · '.($failed ?? 0).' failed'), '', ($failed ?? 0) > 0 ? 'is-danger' : '')
                    .'</div>'),
                Html::make('<h3 class="cp-ov__h">Usage Metrics</h3><div class="cp-ov__grid">'
                    .$this->cell('Users', isset($m['app_users']) && is_numeric($m['app_users']) ? (string) $m['app_users'] : '—', 'app database', '')
                    .$this->cell('DB size', $B::bytes(is_numeric($m['db_size'] ?? null) ? (int) $m['db_size'] : null), '', '')
                    .$this->cell('Functions', (string) (int) ($m['functions'] ?? 0), 'deployed', '')
                    .$this->cell('Backup', $lastBackup ? ucfirst((string) $lastBackup->status) : '—', $lastBackup && $lastBackup->finished_at ? $lastBackup->finished_at->format('M j, H:i') : 'no run yet', $lastBackup && $lastBackup->status === 'ok' ? 'is-success' : 'is-warning')
                    .'</div>'),
                Html::make('<h3 class="cp-ov__h">Quick actions</h3>'.$this->quickActionsHtml($p)),
                Grid::make(2)->schema([
                    Section::make('Recent activity')
                        ->description('No events does not imply a healthy system.')
                        ->extraAttributes(['class' => 'cp-ov__panel'])
                        ->schema([
                            Html::make($this->activityHtml($data['activity'])),
                        ])->compact(),
                    Section::make('Needs attention')
                        ->extraAttributes(['class' => 'cp-ov__panel'])
                        ->schema([
                            Html::make($this->attentionHtml($health, $lastBackup, $failed, $errors)),
                        ])->compact(),
                ]),
            ]);
    }

    protected function cell(string $label, string $value, string $sub, string $tone): string
    {
        return '<div class="cp-ov__cell"><span class="cp-ov__label">'.e($label).'</span>'
            .'<span class="cp-ov__value '.e($tone).'">'.e($value).'</span>'
            .($sub !== '' ? '<span class="cp-ov__sub">'.e($sub).'</span>' : '').'</div>';
    }

    protected function contextHtml(Project $p): string
    {
        $env = strtoupper((string) ($p->environment ?? 'local'));
        $health = ucfirst((string) ($p->health_status ?? 'unknown'));
        $domain = $p->api_domain ?: $p->slug;

        return '<div class="cp-ov__context"><span class="cp-badge">'.$env.'</span>'
            .'<span class="cp-badge '.($p->health_status === 'healthy' ? 'is-success' : ($p->health_status === 'unhealthy' ? 'is-danger' : 'is-warning')).'">Health '.$health.'</span>'
            .'<code class="cp-ov__domain">'.e($domain).'</code></div>';
    }

    /** @return string HTML pill links; only to registered pages (no dead links). */
    protected function quickActionsHtml(Project $p): string
    {
        $actions = [
            'sql' => 'SQL Editor',
            'database' => 'Table Editor',
            'logs' => 'Logs',
        ];
        $html = '<div class="cp-qa cp-ov__actions">';
        foreach ($actions as $page => $label) {
            if (! ProjectResource::hasPage($page)) {
                continue;
            }
            $html .= '<a class="cp-qa__btn" href="'.e(static::projectUrl($p, $page)).'">'.e($label).' →</a>';
        }

        return $html.'</div>';
    }

    /** @param list<array{time:string,text:string}> $activity */
    protected function activityHtml(array $activity): string
    {
        if ($activity === []) {
            return '<div class="cp-ov__notes"><div class="cp-ov__note"><span>No activity recorded yet.</span></div></div>';
        }
        $rows = '';
        foreach (array_slice($activity, 0, 8) as $a) {
            $rows .= '<div class="cp-ov__note"><span class="cp-ov__time">'.e((string) ($a['time'] ?? '—')).'</span>'
                .'<span>'.e((string) ($a['text'] ?? '')).'</span></div>';
        }

        return '<div class="cp-ov__notes">'.$rows.'</div>';
    }

    protected function attentionHtml(array $health, mixed $lastBackup, ?int $failed, int $errors): string
    {
        $rows = '';
        if (($health['ok'] ?? null) === null && empty($health['database'])) {
            $rows .= '<div class="cp-ov__note"><span class="cp-dot is-warning"></span><span>Health has not run — no conclusive result recorded.</span></div>';
        } elseif (($health['ok'] ?? null) === false) {
            $rows .= '<div class="cp-ov__note"><span class="cp-dot is-danger"></span><span>Last health check failed — open Monitoring for detail.</span></div>';
        }
        if (! $lastBackup || ($lastBackup->status ?? null) !== 'ok') {
            $rows .= '<div class="cp-ov__note"><span class="cp-dot is-warning"></span><span>Backup pending — last run: '.e($lastBackup ? (string) ($lastBackup->status ?? 'unknown') : 'never').'.</span></div>';
        }
        if (($failed ?? 0) > 0) {
            $rows .= '<div class="cp-ov__note"><span class="cp-dot is-danger"></span><span>'.$failed.' failed job(s) need review.</span></div>';
        }
        if ($errors > 0) {
            $rows .= '<div class="cp-ov__note"><span class="cp-dot is-warning"></span><span>'.$errors.' API error(s) in the Pulse snapshot.</span></div>';
        }
        if ($rows === '') {
            $rows = '<div class="cp-ov__note"><span class="cp-dot is-healthy"></span><span>All clear.</span></div>';
        }

        return '<div class="cp-ov__notes">'.$rows.'</div>';
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('health_check')
                    ->label('Run health check')
                    ->icon('heroicon-o-heart')
                    ->action(function () {
                        $health = ProjectHealthService::for($this->project())->check();
                        $this->project()->update(['health_status' => $health['ok'] ? 'healthy' : 'unhealthy']);
                        AdminAudit::record('PROJECT_HEALTH_CHECKED', $this->project(), null, null, ['ok' => $health['ok']]);
                        $note = Notification::make()->title($health['ok'] ? 'Project healthy' : 'Project unhealthy — see Overview');
                        $health['ok'] ? $note->success()->send() : $note->danger()->send();
                        $this->redirect(static::getUrl(['record' => $this->project()]));
                    }),
                Action::make('clear_cache')
                    ->label('Clear application cache')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalDescription('Flushes this project\u2019s Redis namespace (cache + queues state for the prefix). Database untouched. Audit logged.')
                    ->action(function () {
                        $prefix = ($this->project()->redis_prefix ?? $this->project()->slug).':';
                        $deleted = 0;
                        foreach (Redis::connection()->keys($prefix.'*') as $key) {
                            Redis::connection()->del($key);
                            $deleted++;
                        }
                        $this->audit('PROJECT_CACHE_CLEARED', null, null, ['keys_deleted' => $deleted, 'prefix' => $prefix]);
                        Notification::make()->title("Cleared {$deleted} keys under {$prefix}")->success()->send();
                    }),
                Action::make('maintenance_on')
                    ->label('Enable maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->visible(fn () => ! $this->project()->maintenance_mode)
                    ->requiresConfirmation()
                    ->action(fn () => $this->setMaintenance(true)),
                Action::make('maintenance_off')
                    ->label('Disable maintenance')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('danger')
                    ->visible(fn () => (bool) $this->project()->maintenance_mode)
                    ->requiresConfirmation()
                    ->action(fn () => $this->setMaintenance(false)),
            ])->label('Actions')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    protected function setMaintenance(bool $on): void
    {
        $downFile = ControlPlanePaths::projectDir($this->project()->slug).'/storage/framework/down';
        if ($on) {
            @mkdir(dirname($downFile), 0755, true);
            file_put_contents($downFile, json_encode(['message' => 'Maintenance via control plane', 'time' => time()]));
            $this->project()->update(['maintenance_mode' => true]);
            $this->audit('PROJECT_MAINTENANCE_ENABLED');
            Notification::make()->title('Maintenance mode enabled')->warning()->send();
        } else {
            @unlink($downFile);
            $this->project()->update(['maintenance_mode' => false]);
            $this->audit('PROJECT_MAINTENANCE_DISABLED');
            Notification::make()->title('Maintenance mode disabled')->success()->send();
        }
        $this->redirect(static::getUrl(['record' => $this->project()]));
    }
}
