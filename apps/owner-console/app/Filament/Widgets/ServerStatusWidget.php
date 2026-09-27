<?php

namespace App\Filament\Widgets;

use App\Models\BackupRecord;
use App\Models\InfrastructureEvent;
use App\Models\Project;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Schemas\Components\Component;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Control-plane dashboard cards. Every number is measured live where possible;
 * container-view host metrics are labeled as such (VPS shows node values).
 */
class ServerStatusWidget extends BaseWidget
{
    protected ?string $heading = 'Infrastructure signals';

    protected ?string $description = 'Project totals, storage and backup activity. Host metrics reflect the container view.';

    public function getSectionContentComponent(): Component
    {
        return parent::getSectionContentComponent()
            ->extraAttributes(['class' => 'cp-reference cp-reference--dashboard cp-reference__metrics']);
    }

    protected function getStats(): array
    {
        try {
            $dbAgg = DB::connection('pgsql-monitor')->selectOne(
                'SELECT count(*) AS dbs, coalesce(sum(pg_database_size(datname)),0) AS bytes FROM pg_database WHERE datistemplate = false'
            );
            $dbCount = (int) ($dbAgg->dbs ?? 0);
            $dbBytes = (int) ($dbAgg->bytes ?? 0);
        } catch (\Throwable) {
            $dbCount = 0;
            $dbBytes = 0;
        }

        $projects = Project::all();
        $healthy = $projects->where('health_status', 'healthy')->count();
        $unhealthy = $projects->where('health_status', 'unhealthy')->count();
        $unknown = $projects->count() - $healthy - $unhealthy;

        $appUsers = 0;
        $failedJobs = 0;
        foreach ($projects as $project) {
            try {
                $conn = ProjectConnectionManager::connection($project);
                if (DB::connection($conn)->getSchemaBuilder()->hasTable('users')) {
                    $appUsers += DB::connection($conn)->table('users')->count();
                }
                if (DB::connection($conn)->getSchemaBuilder()->hasTable('failed_jobs')) {
                    $failedJobs += DB::connection($conn)->table('failed_jobs')->count();
                }
            } catch (\Throwable) {
            }
        }
        try {
            $failedJobs += DB::table('failed_jobs')->count();
        } catch (\Throwable) {
        }

        $fileBytes = 0;
        foreach (glob('/projects/*/storage/control-plane', GLOB_ONLYDIR) ?: [] as $dir) {
            $fileBytes += $this->dirBytes($dir);
        }

        [$memPct, $memLabel] = $this->memory();
        [$cpuLabel, $diskPct, $diskLabel] = [$this->load(), ...$this->disk()];

        $lastBackup = BackupRecord::latest('finished_at')->first();

        return [
            Stat::make('Projects', (string) $projects->count())
                ->description($projects->isEmpty() ? 'No projects registered yet' : "{$healthy} healthy · {$unhealthy} unhealthy · {$unknown} unknown")
                ->color($unhealthy > 0 ? 'danger' : ($unknown > 0 ? 'warning' : ($projects->isEmpty() ? 'gray' : 'success'))),
            Stat::make('Application users', (string) $appUsers)
                ->description('across reachable project databases')->color('primary'),
            Stat::make('DB storage', self::bytes($dbBytes))
                ->description("{$dbCount} databases")->color('info'),
            Stat::make('File storage', self::bytes($fileBytes))
                ->description('control-plane buckets')->color('info'),
            Stat::make('Failed jobs', (string) $failedJobs)
                ->description(InfrastructureEvent::count().' infra events')->color($failedJobs > 0 ? 'danger' : 'success'),
            Stat::make('Last backup', $lastBackup ? ($lastBackup->finished_at?->diffForHumans() ?? '—') : 'none')
                ->description($lastBackup ? "{$lastBackup->db_name} · {$lastBackup->status}" : 'no runs yet')
                ->color($lastBackup && $lastBackup->status === 'ok' ? 'success' : 'warning'),
            Stat::make('RAM (container)', $memLabel)->description('container view')->color($memPct !== null && $memPct > 85 ? 'danger' : 'gray'),
            Stat::make('CPU load (container)', $cpuLabel)->description('1-min average')->color('gray'),
            Stat::make('Disk (container)', $diskLabel)->description('control-plane volume')->color($diskPct !== null && $diskPct > 80 ? 'danger' : 'gray'),
        ];
    }

    /** @return array{?float,string} */
    protected function memory(): array
    {
        try {
            $info = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $info, $t);
            preg_match('/MemAvailable:\s+(\d+)/', $info, $a);
            if (! isset($t[1], $a[1]) || (int) $t[1] === 0) {
                return [null, 'n/a'];
            }
            $pct = 100 * (1 - ((int) $a[1] / (int) $t[1]));

            return [$pct, round($pct).'% used'];
        } catch (\Throwable) {
            return [null, 'n/a'];
        }
    }

    protected function load(): string
    {
        try {
            $load = sys_getloadavg();

            return $load ? (string) round($load[0], 2) : 'n/a';
        } catch (\Throwable) {
            return 'n/a';
        }
    }

    /** @return array{?float,string} */
    protected function disk(): array
    {
        try {
            $free = disk_free_space('/var/www/html');
            $total = disk_total_space('/var/www/html');
            if (! $free || ! $total) {
                return [null, 'n/a'];
            }
            $pct = 100 * (1 - $free / $total);

            return [$pct, round($pct).'% used'];
        } catch (\Throwable) {
            return [null, 'n/a'];
        }
    }

    protected function dirBytes(string $dir): int
    {
        $bytes = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
        } catch (\Throwable) {
        }

        return $bytes;
    }

    public static function bytes(int $b): string
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
