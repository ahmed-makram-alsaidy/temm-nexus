<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthService
{
    /**
     * Liveness + dependency probe for load-balancers and monitoring.
     * Never throws: every check degrades to ok=false with a hint.
     */
    public function check(): array
    {
        $db = $this->probe(fn () => DB::select('select 1 as ok') && true);
        $redis = $this->probe(function () {
            Redis::connection()->ping();
            Cache::put('health:ping', 'pong', 10);

            return Cache::get('health:ping') === 'pong';
        });

        $ok = $db['ok'] && $redis['ok'];

        return [
            'ok' => $ok,
            'app' => config('app.name'),
            'time' => now()->toIso8601String(),
            'database' => $db,
            'redis' => $redis,
        ];
    }

    private function probe(callable $fn): array
    {
        try {
            return ['ok' => (bool) $fn()];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => app()->hasDebugModeEnabled() ? $e->getMessage() : 'unavailable'];
        }
    }
}
