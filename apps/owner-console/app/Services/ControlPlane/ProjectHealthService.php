<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Aggregates per-project health from sources the console can genuinely measure:
 * project DB (reachability, sizes/activity via its canonical connection),
 * Redis (namespaced queue depths), and the project checkout on disk
 * (composer.lock versions, maintenance file, config presence).
 */
class ProjectHealthService
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    public function check(): array
    {
        $db = $this->database();
        $redis = $this->redis();
        $app = $this->application();
        $ok = $db['reachable'] && $redis['reachable'];

        return [
            'ok' => $ok,
            'project' => $this->project->slug,
            'checked_at' => now()->toIso8601String(),
            'database' => $db,
            'redis' => $redis,
            'application' => $app,
        ];
    }

    protected function database(): array
    {
        try {
            $conn = ProjectConnectionManager::connection($this->project);
            DB::connection($conn)->select('select 1');
            $version = DB::connection($conn)->selectOne('SELECT version() AS v')->v ?? '';
            $size = 0;
            $conns = 0;
            try {
                $row = DB::connection($conn)->selectOne(
                    'SELECT pg_database_size(current_database()) AS b, (SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()) AS c'
                );
                $size = (int) ($row->b ?? 0);
                $conns = (int) ($row->c ?? 0);
            } catch (\Throwable) {
            }

            return ['scope' => 'project_database', 'environment_id' => EnvironmentContext::active($this->project)->id,
                'reachable' => true, 'version' => $version, 'size_bytes' => $size, 'connections' => $conns];
        } catch (\Throwable $e) {
            return ['scope' => 'project_database', 'environment_id' => EnvironmentContext::active($this->project)->id,
                'reachable' => false, 'error' => 'unavailable'];
        }
    }

    protected function redis(): array
    {
        try {
            Redis::connection()->ping();
            $prefix = ($this->project->redis_prefix ?? $this->project->slug).':';
            $pending = 0;
            foreach (Redis::connection()->keys($prefix.'queues:*') as $key) {
                $pending += Redis::connection()->llen($key);
            }

            return ['reachable' => true, 'pending_jobs' => $pending];
        } catch (\Throwable) {
            return ['reachable' => false, 'pending_jobs' => 0];
        }
    }

    protected function application(): array
    {
        $base = ControlPlanePaths::projectDir($this->project->slug);
        $info = [
            'checkout_present' => is_dir($base),
            'laravel_version' => null,
            'php_requirement' => null,
            'maintenance_mode' => false,
            'reverb_configured' => 'Not configured',
            'mail_configured' => 'Not configured',
        ];
        $lock = $base.'/composer.lock';
        if (is_file($lock)) {
            $data = json_decode(file_get_contents($lock), true);
            foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
                if (($pkg['name'] ?? '') === 'laravel/framework') {
                    $info['laravel_version'] = ltrim($pkg['version'] ?? '', 'v');
                }
            }
            $info['php_requirement'] = json_decode(file_get_contents($base.'/composer.json'), true)['require']['php'] ?? null;
        }
        $info['maintenance_mode'] = is_file($base.'/storage/framework/down');
        $envFile = $base.'/.env';
        if (is_file($envFile)) {
            $env = file_get_contents($envFile);
            $info['reverb_configured'] = str_contains($env, 'REVERB_APP_KEY=local-key') || ! preg_match('/^REVERB_APP_KEY=.+/m', $env)
                ? 'Not configured' : 'Configured';
            $info['mail_configured'] = preg_match('/^MAIL_HOST=(mailpit|localhost|127\.0\.0\.1)/m', $env) ? 'Local only' : 'Configured';
        }

        return $info;
    }
}
