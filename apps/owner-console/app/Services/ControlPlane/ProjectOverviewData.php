<?php

namespace App\Services\ControlPlane;

use App\Models\AdminAuditEntry;
use App\Models\BackupRecord;
use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\ProjectSchemaChange;
use App\Models\ProjectTask;
use App\Models\ProjectWebhook;
use App\Support\ActivityHumanizer;
use App\Support\ProductStatus;
use Illuminate\Support\Facades\DB;

/**
 * Phase 20D operational-home data for one project. All reads are best-effort:
 * any unreachable source degrades to null instead of breaking the page.
 */
class ProjectOverviewData
{
    public static function for(Project $project): array
    {
        $health = self::safe(fn () => ProjectHealthService::for($project)->check(), []);

        return [
            'health' => $health,
            'metrics' => [
                'app_users' => self::safe(fn () => self::tableCount($project, 'users')),
                'db_size' => self::safe(fn () => self::dbSize($project)),
                'storage' => self::safe(fn () => ProjectStorageManager::for($project)->totalBytes()),
                'queue_failed' => self::safe(fn () => self::tableCount($project, 'failed_jobs')),
                'pulse' => self::safe(fn () => PulseReader::for($project)->entryCounts(), []),
                'functions' => self::safe(fn () => ProjectFunction::query()->where('project_id', $project->id)->count(), 0),
                'webhooks' => self::safe(fn () => ProjectWebhook::query()->where('project_id', $project->id)->count(), 0),
                'tasks' => self::safe(fn () => ProjectTask::query()->where('project_id', $project->id)->count(), 0),
                'last_backup' => self::safe(fn () => ProjectBackupService::for($project)->lastBackup()),
            ],
            'activity' => self::activity($project),
        ];
    }

    protected static function safe(callable $fn, mixed $fallback = null): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    protected static function tableCount(Project $project, string $table): ?int
    {
        $explorer = ProjectDatabaseExplorer::for($project);
        foreach ($explorer->tables() as $t) {
            if ($t['name'] === $table && $t['type'] === 'table') {
                return $explorer->exactCount($table);
            }
        }

        return null;
    }

    protected static function dbSize(Project $project): ?int
    {
        $name = ProjectConnectionManager::connection($project);
        $dbName = config("database.connections.{$name}.database");
        $row = DB::connection($name)->selectOne('SELECT pg_database_size(?) AS bytes', [$dbName]);

        return $row ? (int) $row->bytes : null;
    }

    /** @return list<array{time:?string,text:string,kind:string}> */
    public static function activity(Project $project, int $limit = 10): array
    {
        $items = [];
        foreach (
            AdminAuditEntry::query()->where('project_id', $project->id)
                ->orderByDesc('id')->limit($limit)->get() as $e
        ) {
            $items[] = [
                'at' => (string) $e->created_at,
                'time' => $e->created_at?->format('M j, H:i') ?? '—',
                // 0.6.0 Phase A (§A6): human sentences on product surfaces.
                // The raw verb and target remain in the audit log itself.
                'text' => ActivityHumanizer::humanize($e->action).($e->target_type ? " · {$e->target_type}".($e->target_id ? " #{$e->target_id}" : '') : ''),
                'kind' => 'security',
            ];
        }
        foreach (
            BackupRecord::query()->where('db_name', $project->db_name)
                ->orderByDesc('id')->limit(3)->get() as $b
        ) {
            $items[] = [
                'at' => (string) $b->created_at,
                'time' => $b->created_at?->format('M j, H:i') ?? '—',
                // 0.6.0 Phase D (§D10): the overview activity feed is fully
                // translated; the status dictionary owns the state word.
                'text' => __('projects.activity_backup_state', ['state' => ProductStatus::label((string) $b->status)])
                    .($b->size_bytes ? ' · '.self::bytes((int) $b->size_bytes) : ''),
                'kind' => 'backup',
            ];
        }
        foreach (
            ProjectSchemaChange::query()->where('project_id', $project->id)
                ->orderByDesc('id')->limit(3)->get() as $c
        ) {
            $items[] = [
                'at' => (string) $c->created_at,
                'time' => $c->created_at?->format('M j, H:i') ?? '—',
                'text' => __('projects.activity_schema_change', [
                    'what' => str_replace('_', ' ', $c->kind).' · '.($c->detail['table'] ?? $c->detail['function'] ?? ''),
                ]),
                'kind' => 'schema',
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, $limit);
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
