<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Reads Horizon runtime state from the project's Redis namespace.
 * Horizon stores everything under its configured prefix (per-project).
 * All reads are best-effort: missing keys simply mean Horizon never ran.
 */
class HorizonStatsReader
{
    public function __construct(protected Project $project) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    protected function prefix(): string
    {
        return ($this->project->redis_prefix ?? $this->project->slug).':horizon:';
    }

    public function stats(): array
    {
        $out = [
            'running' => false,
            'supervisors' => [],
            'queues' => [],
            'processed' => 0,
            'failed' => 0,
            'recent_failed' => [],
        ];
        try {
            $r = Redis::connection();
            $p = $this->prefix();
            $masters = $r->keys($p.'masters:*');
            $out['running'] = $masters !== [];
            foreach ($r->keys($p.'supervisors:*') as $key) {
                $out['supervisors'][] = ['name' => basename(str_replace(':', '/', $key)), 'info' => $r->hgetall($key)];
            }
            // Queue depths: horizon:<queue> lists hold waiting jobs per connection.
            foreach ($r->keys($p.'queues:*') as $key) {
                $out['queues'][] = ['queue' => str_replace($p.'queues:', '', $key), 'pending' => $r->llen($key)];
            }
            // Also surface plain project queues (queue:work style).
            $appPrefix = ($this->project->redis_prefix ?? $this->project->slug).':';
            foreach ($r->keys($appPrefix.'queues:*') as $key) {
                $out['queues'][] = ['queue' => str_replace($appPrefix, '', $key), 'pending' => $r->llen($key)];
            }
            $out['processed'] = (int) ($r->get($p.'processed_jobs') ?? 0)
                + array_sum(array_map(fn ($k) => (int) $r->get($k), $r->keys($p.'processed:*')));
            $out['failed'] = (int) ($r->get($p.'failed_jobs') ?? 0);
        } catch (\Throwable) {
        }

        try {
            if (ProjectConnectionManager::ping($this->project)) {
                $conn = ProjectConnectionManager::connection($this->project);
                $out['failed'] = (int) DB::connection($conn)->table('failed_jobs')->count();
                $out['recent_failed'] = DB::connection($conn)->table('failed_jobs')
                    ->select(['id', 'queue', 'exception', 'failed_at'])
                    ->orderByDesc('failed_at')->limit(20)->get()
                    ->map(fn ($j) => [
                        'id' => $j->id,
                        'queue' => $j->queue,
                        'exception' => mb_substr($j->exception ?? '', 0, 200),
                        'failed_at' => $j->failed_at,
                    ])->all();
            }
        } catch (\Throwable) {
        }

        return $out;
    }
}
