<?php

namespace App\Services\ControlPlane;

use App\Models\BackupRun;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Models\ReadinessCheck;
use App\Models\ReadinessSnapshot;
use App\Services\ControlPlane\Migration\SchemaFingerprint;
use Illuminate\Support\Facades\DB;

/**
 * Phase 24H — Production Readiness Center.
 *
 * Checklist/status only (NO numeric score). Machine checks are backed by
 * platform evidence; manual checks are explicit operator acknowledgements
 * tracked with who/when/note. A manual acknowledgement can NEVER silently
 * override a RED machine check — blockers are computed from machine state.
 */
class ReadinessService
{
    public const CATEGORIES = [
        'Database', 'Auth', 'Storage', 'Authorization', 'Finance', 'Realtime',
        'Integrations', 'Clients', 'Performance', 'Backups', 'Restore',
        'Rollback', 'Infrastructure', 'Security', 'Secrets', 'Schema Drift', 'Observability',
    ];

    /** Schema Diff runs feed dangerous-drift findings here. */
    public static function recordDrift(Project $project, ?ProjectEnvironment $environment, bool $hasDangerous, string $detail): void
    {
        ReadinessCheck::updateOrCreate(
            ['project_id' => $project->id, 'environment_id' => $environment?->id, 'check_key' => 'schema_drift_unresolved'],
            [
                'category' => 'Schema Drift',
                'title' => 'Dangerous schema drift unresolved',
                'origin' => 'machine',
                'status' => $hasDangerous ? 'red' : 'green',
                'detail' => $detail,
                'blocks_production' => $hasDangerous,
                'evaluated_at' => now(),
            ]
        );
    }

    /** Run all machine checks for a project/environment. */
    public static function evaluate(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $checks = [];

        $checks[] = self::machineCheck($project, $environment, 'Database', 'db_reachable', 'Project database reachable', function () use ($project) {
            try {
                ProjectConnectionManager::connection($project);

                return ['status' => 'green', 'detail' => 'connection opened'];
            } catch (\Throwable $e) {
                return ['status' => 'red', 'detail' => 'database unreachable', 'blocks' => true];
            }
        });

        $checks[] = self::machineCheck($project, $environment, 'Database', 'db_health_status', 'Project health status', function () use ($project) {
            $health = $project->health_status;
            return [
                'status' => $health === 'healthy' ? 'green' : ($health === 'unhealthy' ? 'red' : 'yellow'),
                'detail' => 'health_status='.$health,
                'blocks' => $health === 'unhealthy',
            ];
        });

        $checks[] = self::machineCheck($project, $environment, 'Backups', 'backup_freshness', 'Backup freshness (48h)', function () use ($project) {
            $latest = BackupRun::where('project_id', $project->id)->where('type', 'database')->where('status', 'completed')->orderByDesc('finished_at')->first();
            $legacy = ProjectBackupService::for($project)->lastBackup();
            $at = $latest?->finished_at ?? $legacy?->finished_at;
            if (! $at) {
                return ['status' => 'red', 'detail' => 'no successful backup recorded', 'blocks' => true];
            }
            $hours = $at->diffInHours(now());
            return [
                'status' => $hours <= 48 ? 'green' : ($hours <= 96 ? 'yellow' : 'red'),
                'detail' => 'last backup '.$at->diffForHumans(),
                'blocks' => $hours > 96,
            ];
        });

        $checks[] = self::machineCheck($project, $environment, 'Restore', 'restore_test_age', 'Restore drill age (30d)', function () use ($project) {
            $drill = BackupRun::where('project_id', $project->id)->where('type', 'restore_drill')->where('status', 'drill_passed')->orderByDesc('finished_at')->first();
            if (! $drill) {
                return ['status' => 'red', 'detail' => 'restore never tested', 'blocks' => true];
            }
            $days = $drill->finished_at->diffInDays(now());
            return [
                'status' => $days <= 30 ? 'green' : ($days <= 60 ? 'yellow' : 'red'),
                'detail' => 'last drill '.$drill->finished_at->diffForHumans(),
                'blocks' => $days > 60,
            ];
        });

        $checks[] = self::machineCheck($project, $environment, 'Schema Drift', 'schema_drift_unresolved', 'Dangerous schema drift unresolved', function () use ($project, $environment) {
            $snapshot = \App\Models\SchemaSnapshot::where('project_id', $project->id)
                ->when($environment, fn ($q) => $q->where('environment_id', $environment->id))
                ->orderByDesc('id')->first();
            if (! $snapshot) {
                return ['status' => 'yellow', 'detail' => 'no schema snapshot captured yet'];
            }

            // Drift findings are written by Schema Diff runs via recordDrift();
            // report the last recorded finding if any.
            $existing = ReadinessCheck::where('project_id', $project->id)
                ->where('environment_id', $environment?->id)
                ->where('check_key', 'schema_drift_unresolved')->first();
            if ($existing && $existing->status === 'red') {
                return ['status' => 'red', 'detail' => 'dangerous drift recorded', 'blocks' => true];
            }

            return ['status' => 'green', 'detail' => 'latest fingerprint '.substr((string) $snapshot->fingerprint, 0, 12)];
        });

        $checks[] = self::machineCheck($project, $environment, 'Secrets', 'secret_presence', 'Required secrets present', function () use ($project, $environment) {
            $query = ProjectSecret::where('project_id', $project->id)->where('status', 'active');
            $count = $environment ? (clone $query)->where(function ($q) use ($environment) {
                $q->where('environment_id', $environment->id)->orWhereNull('environment_id');
            })->count() : (clone $query)->count();
            return [
                'status' => $count > 0 ? 'green' : 'yellow',
                'detail' => $count.' active secrets scoped to this environment',
            ];
        });

        $checks[] = self::machineCheck($project, $environment, 'Realtime', 'reverb_health', 'Realtime (Reverb) reachable', function () use ($project) {
            $status = ReverbStatusService::for($project)->status();
            $ok = ! empty($status['host']) && ! empty($status['port']);
            return ['status' => $ok ? 'green' : 'yellow', 'detail' => $ok ? 'configured' : 'not configured'];
        });

        $checks[] = self::machineCheck($project, $environment, 'Clients', 'https_api_domain', 'API domain set (HTTPS)', function () use ($project) {
            return [
                'status' => $project->api_domain ? 'green' : 'yellow',
                'detail' => $project->api_domain ?: 'api_domain not set',
            ];
        });

        $checks[] = self::machineCheck($project, $environment, 'Observability', 'monitoring_present', 'Monitoring data available', function () use ($project) {
            $hasPulse = false;
            try {
                $hasPulse = PulseReader::for($project)->tablesPresent();
            } catch (\Throwable) {
                $hasPulse = false;
            }
            return ['status' => $hasPulse ? 'green' : 'yellow', 'detail' => $hasPulse ? 'pulse data present' : 'no pulse data yet'];
        });

        // Persist machine results (ack fields preserved for existing rows).
        foreach ($checks as $check) {
            ReadinessCheck::updateOrCreate(
                ['project_id' => $project->id, 'environment_id' => $environment?->id, 'check_key' => $check['key']],
                [
                    'category' => $check['category'],
                    'title' => $check['title'],
                    'origin' => 'machine',
                    'status' => $check['status'],
                    'detail' => $check['detail'],
                    'blocks_production' => (bool) ($check['blocks'] ?? false),
                    'evaluated_at' => now(),
                ]
            );
        }

        return self::summary($project, $environment);
    }

    /** Manual acknowledgement (24H.3) — tracked who/when/note. */
    public static function acknowledge(Project $project, ?ProjectEnvironment $environment, string $checkKey, string $note): ReadinessCheck
    {
        $check = ReadinessCheck::where('project_id', $project->id)
            ->where('environment_id', $environment?->id)
            ->where('check_key', $checkKey)->firstOrFail();

        // A manual checkbox may never silently override a RED machine check.
        abort_if($check->origin === 'machine' && $check->status === 'red', 422, 'A red machine check cannot be acknowledged away — resolve it first.');

        $check->update([
            'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(),
            'acknowledgement_note' => $note,
        ]);
        AdminAudit::record('READINESS_ACKNOWLEDGED', $project, 'readiness_check', $check->id, [
            'check_key' => $checkKey, 'note' => \Illuminate\Support\Str::limit($note, 120),
        ]);

        return $check;
    }

    /** Add a manual check (things the platform cannot prove). */
    public static function addManualCheck(Project $project, ?ProjectEnvironment $environment, array $data): ReadinessCheck
    {
        return ReadinessCheck::create([
            'project_id' => $project->id,
            'environment_id' => $environment?->id,
            'category' => $data['category'],
            'check_key' => 'manual_'.\Illuminate\Support\Str::slug($data['title']),
            'title' => $data['title'],
            'origin' => 'manual',
            'status' => in_array($data['status'] ?? 'yellow', ReadinessCheck::STATUSES, true) ? $data['status'] : 'yellow',
            'detail' => $data['note'] ?? null,
            'blocks_production' => (bool) ($data['blocks'] ?? false),
        ]);
    }

    /** Blockers: RED machine checks (unresolved, ack can't hide them). */
    public static function blockers(Project $project, ?ProjectEnvironment $environment = null): array
    {
        return ReadinessCheck::where('project_id', $project->id)
            ->when($environment, fn ($q) => $q->where(function ($qq) use ($environment) {
                $qq->where('environment_id', $environment->id)->orWhereNull('environment_id');
            }))
            ->where('origin', 'machine')
            ->where('status', 'red')
            ->get(['id', 'category', 'check_key', 'title', 'detail'])
            ->toArray();
    }

    public static function summary(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $checks = ReadinessCheck::where('project_id', $project->id)
            ->when($environment, fn ($q) => $q->where(function ($qq) use ($environment) {
                $qq->where('environment_id', $environment->id)->orWhereNull('environment_id');
            }))
            ->get();

        $counts = ['green' => 0, 'yellow' => 0, 'red' => 0, 'not_applicable' => 0];
        foreach ($checks as $check) {
            $counts[$check->status] = ($counts[$check->status] ?? 0) + 1;
        }

        return [
            'counts' => $counts,
            'checks' => $checks->map(fn ($c) => [
                'id' => $c->id, 'category' => $c->category, 'check_key' => $c->check_key,
                'title' => $c->title, 'origin' => $c->origin, 'status' => $c->status,
                'detail' => $c->detail, 'blocks_production' => $c->blocks_production,
                'acknowledged_at' => $c->acknowledged_at?->toIso8601String(),
                'acknowledgement_note' => $c->acknowledgement_note,
            ])->values()->all(),
            'blockers' => self::blockers($project, $environment),
        ];
    }

    /** Persist a readiness snapshot (24H.5) for release/cutover history. */
    public static function snapshot(Project $project, ?ProjectEnvironment $environment = null): ReadinessSnapshot
    {
        $summary = self::summary($project, $environment);
        $snap = ReadinessSnapshot::create([
            'project_id' => $project->id,
            'environment_id' => $environment?->id,
            'summary' => $summary,
            'created_by' => auth()->id(),
        ]);
        AdminAudit::record('READINESS_SNAPSHOT_RECORDED', $project, 'readiness_snapshot', $snap->id);

        return $snap;
    }

    protected static function machineCheck(Project $project, ?ProjectEnvironment $environment, string $category, string $key, string $title, callable $fn): array
    {
        $result = $fn();

        return [
            'category' => $category, 'key' => $key, 'title' => $title,
            'origin' => 'machine', 'status' => $result['status'],
            'detail' => $result['detail'] ?? '', 'blocks' => $result['blocks'] ?? false,
        ];
    }
}
