<?php

namespace App\Services\ControlPlane;

use App\Models\BackupRun;
use App\Models\CostEntry;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ResourceMetricSample;
use App\Models\ResourceThreshold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Phase 24G — Resource & Cost Observability. Collects only metrics the
 * infrastructure actually exposes; anything unavailable is reported as
 * unavailable, never fabricated. Node-level CPU/RAM are labelled
 * attribution=node because per-project splits are not measurable here.
 */
class ResourceObservabilityService
{
    public const METRICS = [
        'db_size_bytes' => ['unit' => 'bytes', 'attribution' => 'project'],
        'db_connections' => ['unit' => 'count', 'attribution' => 'project'],
        'redis_memory_bytes' => ['unit' => 'bytes', 'attribution' => 'project'],
        'queue_depth' => ['unit' => 'count', 'attribution' => 'project'],
        'failed_jobs' => ['unit' => 'count', 'attribution' => 'project'],
        'storage_bytes' => ['unit' => 'bytes', 'attribution' => 'project'],
        'backup_bytes' => ['unit' => 'bytes', 'attribution' => 'project'],
        'log_errors_24h' => ['unit' => 'count', 'attribution' => 'project'],
        'node_cpu_percent' => ['unit' => 'percent', 'attribution' => 'node'],
        'node_ram_used_bytes' => ['unit' => 'bytes', 'attribution' => 'node'],
    ];

    /** Collect one round of samples. Returns collected metric keys. */
    public static function collect(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $collected = [];

        // DB size + connections — only when the project DB is reachable.
        try {
            $connection = ProjectConnectionManager::connection($project);
            $size = DB::connection($connection)->selectOne('SELECT pg_database_size(current_database()) AS size');
            $collected['db_size_bytes'] = (int) ($size->size ?? 0);
            $conns = DB::connection($connection)->selectOne(
                "SELECT COUNT(*) AS c FROM pg_stat_activity WHERE datname = current_database()"
            );
            $collected['db_connections'] = (int) ($conns->c ?? 0);
        } catch (\Throwable) {
            // Honest absence: project DB unreachable.
        }

        // Redis memory + queue depth via the project namespace.
        try {
            $redis = ProjectRedisManager::connection($project);
            $info = $redis->info('memory');
            $collected['redis_memory_bytes'] = (int) ($info['used_memory'] ?? 0);
            $depth = 0;
            foreach (['queues:default', 'queues'] as $key) {
                $depth += (int) $redis->llen($key);
            }
            $collected['queue_depth'] = $depth;
            $failed = (int) $redis->zcard('queues:failed');
            $collected['failed_jobs'] = $failed;
        } catch (\Throwable) {
            // Redis namespace unavailable.
        }

        // Storage bytes on disk (project checkout storage dir).
        $dir = ControlPlanePaths::projectDir($project->slug);
        if ($dir && is_dir($dir)) {
            $result = Process::timeout(30)->run(['du', '-sb', $dir]);
            if ($result->successful()) {
                $collected['storage_bytes'] = (int) (strtok(trim($result->output()), "\t") ?: 0);
            }
        }

        // Backup bytes from recorded runs.
        $collected['backup_bytes'] = (int) BackupRun::where('project_id', $project->id)
            ->where('type', 'database')->where('status', 'completed')->sum('size_bytes') ?: 0;

        // Node-level metrics (honest attribution): /proc inside the console container.
        $nodeCpu = self::nodeCpuPercent();
        if ($nodeCpu !== null) {
            $collected['node_cpu_percent'] = $nodeCpu;
        }
        $mem = self::nodeMemory();
        if ($mem !== null) {
            $collected['node_ram_used_bytes'] = $mem;
        }

        $now = now();
        foreach ($collected as $metric => $value) {
            ResourceMetricSample::create([
                'project_id' => $project->id,
                'environment_id' => $environment?->id,
                'metric' => $metric,
                'value' => $value,
                'unit' => self::METRICS[$metric]['unit'] ?? 'count',
                'attribution' => self::METRICS[$metric]['attribution'] ?? 'project',
                'sampled_at' => $now,
            ]);
        }

        return $collected;
    }

    /** Latest sample per metric + threshold warnings. */
    public static function current(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $rows = [];
        foreach (array_keys(self::METRICS) as $metric) {
            $sample = ResourceMetricSample::where('project_id', $project->id)
                ->where('metric', $metric)
                ->when($environment, fn ($q) => $q->where(function ($qq) use ($environment) {
                    $qq->where('environment_id', $environment->id)->orWhereNull('environment_id');
                }))
                ->orderByDesc('sampled_at')->first();
            if (! $sample) {
                $rows[$metric] = ['value' => null, 'status' => 'unavailable'];
                continue;
            }
            $threshold = ResourceThreshold::where('project_id', $project->id)->where('metric', $metric)->first();
            $status = 'ok';
            if ($threshold) {
                if ($threshold->critical_value !== null && $sample->value >= $threshold->critical_value) {
                    $status = 'critical';
                } elseif ($sample->value >= $threshold->warning_value) {
                    $status = 'warning';
                }
            }
            $rows[$metric] = [
                'value' => $sample->value,
                'unit' => $sample->unit,
                'attribution' => $sample->attribution,
                'sampled_at' => $sample->sampled_at->toIso8601String(),
                'status' => $status,
            ];
        }

        return $rows;
    }

    /** Trend series for a window (24h|7d|30d) — only real retention exists. */
    public static function trend(Project $project, string $metric, string $window = '24h'): array
    {
        $since = match ($window) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        return ResourceMetricSample::where('project_id', $project->id)
            ->where('metric', $metric)
            ->where('sampled_at', '>=', $since)
            ->orderBy('sampled_at')
            ->limit(2000)
            ->get(['value', 'sampled_at'])
            ->toArray();
    }

    public static function setThreshold(Project $project, string $metric, float $warning, ?float $critical): ResourceThreshold
    {
        if (! isset(self::METRICS[$metric])) {
            abort(422, 'Unknown metric');
        }
        $threshold = ResourceThreshold::updateOrCreate(
            ['project_id' => $project->id, 'metric' => $metric],
            ['warning_value' => $warning, 'critical_value' => $critical]
        );
        AdminAudit::record('RESOURCE_THRESHOLD_UPDATED', $project, 'threshold', $threshold->id, [
            'metric' => $metric,
        ]);

        return $threshold;
    }

    /** Capacity warnings across metrics. */
    public static function warnings(Project $project): array
    {
        $warnings = [];
        foreach (self::current($project) as $metric => $row) {
            if (in_array($row['status'] ?? '', ['warning', 'critical'], true)) {
                $warnings[] = ['metric' => $metric, 'status' => $row['status'], 'value' => $row['value']];
            }
        }

        return $warnings;
    }

    // ── Cost estimation (24G.5) — operator-entered, labeled ESTIMATE ────

    public static function recordCost(Project $project, array $data): CostEntry
    {
        $entry = CostEntry::create([
            'project_id' => $project->id,
            'name' => $data['name'] ?? 'shared infrastructure',
            'monthly_cost' => (float) ($data['monthly_cost'] ?? 0),
            'currency' => $data['currency'] ?? 'EGP',
            'allocation' => in_array($data['allocation'] ?? 'equal', ['equal', 'manual', 'resource_weighted'], true) ? $data['allocation'] : 'equal',
            'month' => (int) ($data['month'] ?? now()->format('Ym')),
            'note' => $data['note'] ?? null,
        ]);
        AdminAudit::record('COST_ENTRY_UPDATED', $project, 'cost_entry', $entry->id, ['month' => $entry->month]);

        return $entry;
    }

    /** Allocate cost across the projects that share the entry scope. */
    public static function allocationEstimate(CostEntry $entry): array
    {
        $projectCount = \App\Models\Project::where('status', 'active')->count();
        $basis = 'equal share of active projects';
        $allocated = $projectCount > 0 ? $entry->monthly_cost / $projectCount : 0.0;

        if ($entry->allocation === 'resource_weighted') {
            // Weight by db_size share — only meaningful when metrics exist.
            $sizes = ResourceMetricSample::whereIn('metric', ['db_size_bytes'])
                ->where('sampled_at', '>=', now()->subDays(7))
                ->orderByDesc('sampled_at')
                ->get()
                ->groupBy('project_id')
                ->map(fn ($g) => $g->first()->value);
            $total = $sizes->sum();
            $own = $sizes[$entry->project_id] ?? 0;
            if ($total > 0) {
                $allocated = $entry->monthly_cost * ($own / $total);
                $basis = 'db size share over last 7 days';
            }
        } elseif ($entry->allocation === 'manual') {
            $allocated = (float) ($entry->allocated_cost ?? 0);
            $basis = 'operator-entered allocation';
        }

        return [
            'estimate' => round($allocated, 2),
            'currency' => $entry->currency,
            'basis' => $basis,
            'label' => 'ESTIMATE — not a billing figure',
        ];
    }

    // ── Node-level collectors ───────────────────────────────────────────

    protected static function nodeCpuPercent(): ?float
    {
        $stat = @file_get_contents('/proc/stat');
        if (! $stat) {
            return null;
        }
        $line = strtok($stat, "\n");
        if (! preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m)) {
            return null;
        }
        $idle = (int) $m[4];
        $total = ((int) $m[1]) + ((int) $m[2]) + ((int) $m[3]) + $idle;
        usleep(100000);
        $stat2 = @file_get_contents('/proc/stat');
        if (! $stat2 || ! preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', strtok($stat2, "\n"), $m2)) {
            return null;
        }
        $idle2 = (int) $m2[4];
        $total2 = ((int) $m2[1]) + ((int) $m2[2]) + ((int) $m2[3]) + $idle2;
        if ($total2 === $total) {
            return 0.0;
        }

        return round((1 - ($idle2 - $idle) / ($total2 - $total)) * 100, 2);
    }

    protected static function nodeMemory(): ?int
    {
        $data = @file_get_contents('/proc/meminfo');
        if (! $data || ! preg_match('/MemTotal:\s+(\d+) kB/', $data, $t) || ! preg_match('/MemAvailable:\s+(\d+) kB/', $data, $a)) {
            return null;
        }

        return ((int) $t[1] - (int) $a[1]) * 1024;
    }
}
