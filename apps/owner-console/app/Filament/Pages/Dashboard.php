<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AdminAuditEntry;
use App\Models\BackupRecord;
use App\Models\Project;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class Dashboard extends BaseDashboard
{
    protected static bool $isDiscovered = false;

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-dash'])
            ->components([Html::make($this->cockpitHtml())]);
    }

    protected function cockpitHtml(): string
    {
        $projects = Project::query()->orderBy('name')->get();
        $healthy = $projects->where('health_status', 'healthy')->count();
        $unhealthy = $projects->where('health_status', 'unhealthy')->count();
        $unknown = $projects->count() - $healthy - $unhealthy;

        $hour = (int) now()->format('G');
        $greeting = $hour < 5 ? 'Good night' : ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening'));

        $h = '<div class="cp-dash__greet"><h2>'.$greeting.'</h2>'
            .'<p>'.$projects->count().' projects · '.$healthy.' healthy · '.($unhealthy + $unknown).' need attention · '.e(now()->format('D, M j')).'</p></div>';

        $systemOk = $unhealthy === 0;
        $h .= '<div class="cp-dash__stats">'
            .$this->stat('Projects', (string) $projects->count(), $healthy.' healthy · '.$unhealthy.' unhealthy · '.$unknown.' unknown', $unhealthy > 0 ? 'is-danger' : 'is-success')
            .$this->stat('System', $systemOk ? 'Healthy' : 'Attention', $this->hostLine(), $systemOk ? 'is-success' : 'is-warning')
            .$this->stat('DB storage', $this->dbBytes(), 'project databases', '')
            .$this->stat('Last backup', $this->backupLine(), $this->backupSub(), $this->backupOk() ? 'is-success' : 'is-warning')
            .'</div>';

        $h .= '<div class="cp-dash__cols"><div class="cp-dash__col">'
            .'<h3 class="cp-dash__h">Recent projects</h3><div class="cp-dash__rows">';
        if ($projects->isEmpty()) {
            $h .= '<div class="cp-dash__row"><span>No projects yet. Create your first backend project or import an existing one.</span></div>'
                .'<div class="cp-dash__row cp-dash__new" style="display:flex;gap:14px;">';
            if (ProjectResource::canCreate()) {
                $h .= '<a class="cp-btn" href="'.e(ProjectResource::getUrl('create')).'">Create new project</a>'
                    .'<a class="cp-btn" href="'.e(\App\Filament\Pages\OnboardingWizard::getUrl(['start' => 'import'])).'">Import existing project</a>';
            }
            $h .= '</div>';
        }
        foreach ($projects as $p) {
            $dot = $p->health_status === 'healthy' ? 'is-healthy' : ($p->health_status === 'unhealthy' ? 'is-danger' : 'is-unknown');
            $h .= '<a class="cp-dash__row" href="'.e(ProjectResource::getUrl('overview', ['record' => $p])).'">'
                .'<span class="cp-dot '.$dot.'"></span>'
                .'<span class="cp-dash__name">'.e($p->name).'</span>'
                .'<span class="cp-badge">'.e(strtoupper((string) ($p->environment ?? 'local'))).'</span>'
                .'<span class="cp-dash__health">'.e(ucfirst((string) ($p->health_status ?? 'unknown'))).'</span></a>';
        }
        if (ProjectResource::canCreate()) {
            $h .= '<a class="cp-dash__row cp-dash__new" href="'.e(ProjectResource::getUrl('create')).'">+ New project</a>';
        }
        $h .= '</div></div><div class="cp-dash__col">'
            .'<h3 class="cp-dash__h">Needs attention</h3><div class="cp-dash__rows">'.$this->attentionRows($projects, $unhealthy, $unknown).'</div>'
            .'<h3 class="cp-dash__h">Recent activity</h3><div class="cp-dash__rows">'.$this->activityRows().'</div>'
            .'</div></div>';

        return $h;
    }

    protected function stat(string $label, string $value, string $sub, string $tone): string
    {
        return '<div class="cp-dash__stat"><span class="cp-dash__label">'.e($label).'</span>'
            .'<span class="cp-dash__value '.e($tone).'">'.e($value).'</span>'
            .'<span class="cp-dash__sub">'.e($sub).'</span></div>';
    }

    protected function hostLine(): string
    {
        try {
            $load = sys_getloadavg();
            $cpu = $load ? round($load[0], 2) : '?';
            $info = (string) @file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $info, $t);
            preg_match('/MemAvailable:\s+(\d+)/', $info, $a);
            $mem = isset($t[1], $a[1]) && (int) $t[1] > 0 ? round(100 * (1 - ((int) $a[1] / (int) $t[1]))).'% RAM' : '? RAM';
            $free = @disk_free_space('/var/www/html');
            $total = @disk_total_space('/var/www/html');
            $disk = $free && $total ? round(100 * (1 - $free / $total)).'% disk' : '? disk';

            return "CPU {$cpu} · {$mem} · {$disk} (container)";
        } catch (\Throwable) {
            return 'container view unavailable';
        }
    }

    protected function dbBytes(): string
    {
        try {
            $row = DB::connection('pgsql-monitor')->selectOne(
                'SELECT coalesce(sum(pg_database_size(datname)),0) AS bytes FROM pg_database WHERE datistemplate = false'
            );

            return $this->bytes((int) ($row->bytes ?? 0));
        } catch (\Throwable) {
            return '—';
        }
    }

    protected function lastBackup(): mixed
    {
        try {
            return BackupRecord::latest('finished_at')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function backupLine(): string
    {
        $b = $this->lastBackup();

        return $b ? ($b->finished_at?->diffForHumans() ?? '—') : 'none';
    }

    protected function backupSub(): string
    {
        $b = $this->lastBackup();

        return $b ? "{$b->db_name} · {$b->status}" : 'no runs yet';
    }

    protected function backupOk(): bool
    {
        $b = $this->lastBackup();

        return (bool) ($b && $b->status === 'ok');
    }

    protected function attentionRows(mixed $projects, int $unhealthy, int $unknown): string
    {
        $rows = '';
        foreach ($projects as $p) {
            if ($p->health_status === 'unhealthy') {
                $rows .= '<a class="cp-dash__row" href="'.e(ProjectResource::getUrl('overview', ['record' => $p])).'">'
                    .'<span class="cp-dot is-danger"></span><span>'.e($p->name).' is unhealthy — run a health check.</span></a>';
            }
        }
        if ($unknown > 0) {
            $rows .= '<div class="cp-dash__row"><span class="cp-dot is-unknown"></span><span>'.$unknown.' project(s) have no conclusive health result.</span></div>';
        }
        $b = $this->lastBackup();
        if (! $b || $b->status !== 'ok') {
            $rows .= '<div class="cp-dash__row"><span class="cp-dot is-warning"></span><span>Backups: '.e($b ? "{$b->status} · {$b->db_name}" : 'no runs yet').'.</span></div>';
        }
        if ($unhealthy === 0 && $b && $b->status === 'ok') {
            $rows .= '<div class="cp-dash__row"><span class="cp-dot is-healthy"></span><span>All clear.</span></div>';
        }

        return $rows;
    }

    protected function activityRows(): string
    {
        try {
            $entries = AdminAuditEntry::query()->orderByDesc('id')->limit(8)->get();
        } catch (\Throwable) {
            return '<div class="cp-dash__row"><span>Activity unavailable.</span></div>';
        }
        if ($entries->isEmpty()) {
            return '<div class="cp-dash__row"><span>No activity recorded yet.</span></div>';
        }
        $names = Project::query()->whereIn('id', $entries->pluck('project_id')->filter()->unique())->pluck('name', 'id');
        $rows = '';
        foreach ($entries as $e) {
            $rows .= '<div class="cp-dash__row"><span class="cp-dash__time">'.e($e->created_at?->format('M j, H:i') ?? '—').'</span>'
                .'<span>'.e($e->action).($e->project_id && isset($names[$e->project_id]) ? ' · '.e($names[$e->project_id]) : '').'</span></div>';
        }

        return $rows;
    }

    protected function bytes(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($b < 1024) {
                return round($b, 1).' '.$u;
            }
            $b /= 1024;
        }

        return round($b, 1).' PB';
    }
}
