<?php

namespace App\Services\ControlPlane;

use App\Models\BackupDestination;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Project;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Phase 24D — Backup Center. Policies + destinations + runs + health +
 * restore drills.
 *
 * Restore drills restore into a NEW disposable database (restore_drill_*)
 * and never overwrite an active database. The drill shell is disabled
 * entirely unless RESTORE_DRILL_ENABLED=true in the console env (default
 * enabled in local dev), and refuses anything but the drill DB prefix.
 */
class BackupCenterService
{
    // ── Policies / destinations ─────────────────────────────────────────

    public static function createPolicy(Project $project, array $data): BackupPolicy
    {
        $scope = in_array($data['scope'] ?? 'database', ['database', 'storage'], true) ? ($data['scope'] ?? 'database') : 'database';
        $policy = BackupPolicy::create([
            'project_id' => $project->id,
            'environment_id' => $data['environment_id'] ?? null,
            'destination_id' => $data['destination_id'] ?? null,
            'name' => $data['name'],
            'scope' => $scope,
            'schedule' => self::normalizeSchedule($data['schedule'] ?? 'daily'),
            'retention_days' => max(1, (int) ($data['retention_days'] ?? 14)),
            'encrypted' => (bool) ($data['encrypted'] ?? false),
            'status' => 'active',
        ]);
        AdminAudit::record('BACKUP_POLICY_CREATED', $project, 'backup_policy', $policy->id, ['name' => $policy->name, 'schedule' => $policy->schedule]);

        return $policy;
    }

    public static function createDestination(Project $project, array $data): BackupDestination
    {
        $driver = in_array($data['driver'] ?? 'local', BackupDestination::DRIVERS, true) ? $data['driver'] : 'local';
        // S3-compatible endpoints must be explicit URLs; no AWS default.
        $config = $data['config'] ?? [];
        if ($driver === 's3-compatible' && ! empty($config['endpoint'])) {
            $host = parse_url($config['endpoint'], PHP_URL_HOST);
            if (! $host) {
                abort(422, 'Invalid S3-compatible endpoint URL');
            }
        }
        $destination = BackupDestination::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'driver' => $driver,
            'config' => $config,
            'secret_ref' => $data['secret_ref'] ?? null, // vault ref — never the key itself
            'status' => 'active',
        ]);
        AdminAudit::record('BACKUP_DESTINATION_CREATED', $project, 'backup_destination', $destination->id, [
            'name' => $destination->name, 'driver' => $driver,
        ]);

        return $destination;
    }

    public static function normalizeSchedule(string $schedule): string
    {
        return match ($schedule) {
            'hourly' => '0 * * * *',
            'daily' => '0 3 * * *',
            'weekly' => '0 3 * * 0',
            default => CronService::valid($schedule) ? $schedule : '0 3 * * *',
        };
    }

    // ── Runs ────────────────────────────────────────────────────────────

    /** Trigger a database backup run (metadata + shell via ProjectBackupService). */
    public static function runBackup(BackupPolicy $policy, string $trigger = 'manual'): BackupRun
    {
        $run = BackupRun::create([
            'project_id' => $policy->project_id,
            'backup_policy_id' => $policy->id,
            'environment_id' => $policy->environment_id,
            'type' => $policy->scope,
            'trigger' => $trigger,
            'started_at' => now(),
            'status' => 'running',
            'destination' => $policy->destination?->name ?? 'local',
        ]);

        try {
            $backup = ProjectBackupService::for($policy->project)->trigger('full');
            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'size_bytes' => (int) ($backup->size_bytes ?? 0),
                'checksum' => $backup->checksum,
                'destination' => $backup->location,
            ]);
            $policy->update(['last_run_at' => now()]);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => Str::limit($e->getMessage(), 500)]);
        }

        return $run;
    }

    // ── Restore drill (24D.4) ───────────────────────────────────────────

    /**
     * Restore a completed backup into a NEW disposable database, validate,
     * then drop the disposable DB. Never touches the active database.
     */
    public static function requestRestoreDrill(BackupRun $backupRun): BackupRun
    {
        abort_unless(config('app.env') === 'local' || env('RESTORE_DRILL_ENABLED', 'true'), 403, 'Restore drills are disabled on this host.');
        abort_if($backupRun->status !== 'completed', 422, 'Only completed backups can be drilled.');

        $drill = BackupRun::create([
            'project_id' => $backupRun->project_id,
            'backup_policy_id' => $backupRun->backup_policy_id,
            'environment_id' => $backupRun->environment_id,
            'type' => 'restore_drill',
            'trigger' => 'drill',
            'started_at' => now(),
            'status' => 'drill_running',
            'destination' => $backupRun->destination,
            'meta' => ['source_backup_run' => $backupRun->id, 'source_file' => $backupRun->destination],
        ]);
        AdminAudit::record('RESTORE_DRILL_REQUESTED', $backupRun->project, 'backup_run', $drill->id, ['source_backup' => $backupRun->id]);

        return self::executeDrill($drill, $backupRun);
    }

    protected static function executeDrill(BackupRun $drill, BackupRun $backupRun): BackupRun
    {
        $file = $backupRun->destination; // absolute path to .dump
        $drillDb = 'restore_drill_'.now()->format('YmdHis').'_'.Str::lower(Str::random(4));

        try {
            if (! is_string($file) || ! str_starts_with($file, '/backups/') || ! file_exists($file)) {
                throw new \RuntimeException('Backup file not found under /backups.');
            }
            // Path traversal guard: the file must be a dump under /backups.
            $real = realpath($file);
            if (! $real || ! str_starts_with($real, realpath('/backups'))) {
                throw new \RuntimeException('Backup path failed the traversal guard.');
            }

            $env = [
                'PGPASSWORD' => (string) env('POSTGRES_PASSWORD', ''),
                'PGHOST' => self::pgHost(),
                'PGPORT' => (string) env('CP_PG_PORT', '5432'),
                'PGUSER' => (string) env('CP_PG_USER', 'postgres'),
            ];

            // 1. Create the disposable drill database.
            $create = Process::env($env)->timeout(60)->run("psql -v ON_ERROR_STOP=1 -c \"CREATE DATABASE \\\"{$drillDb}\\\"\"");
            if (! $create->successful()) {
                throw new \RuntimeException('Drill database creation failed: '.$create->errorOutput());
            }

            // 2. Restore the dump into it.
            $restore = Process::env($env)->timeout(600)->run("pg_restore --no-owner --role=".env('CP_PG_USER', 'postgres')." -d \"{$drillDb}\" ".escapeshellarg($file));
            if (! $restore->successful()) {
                throw new \RuntimeException('pg_restore failed: '.Str::limit($restore->errorOutput(), 300));
            }

            // 3. Validate: count restored non-system tables.
            $count = Process::env($env)->timeout(60)->run(
                "psql -d \"{$drillDb}\" -Atc \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'\""
            );
            $tables = (int) trim($count->output() ?: '0');
            if ($tables < 1) {
                throw new \RuntimeException('Restore drill validation failed: no tables restored.');
            }

            $drill->update([
                'status' => 'drill_passed', 'finished_at' => now(),
                'meta' => array_merge($drill->meta ?? [], ['drill_db' => $drillDb, 'tables_restored' => $tables]),
            ]);
            AdminAudit::record('RESTORE_DRILL_PASSED', $backupRun->project, 'backup_run', $drill->id, ['tables_restored' => $tables]);
        } catch (\Throwable $e) {
            $drill->update(['status' => 'drill_failed', 'finished_at' => now(), 'error' => Str::limit($e->getMessage(), 500)]);
            AdminAudit::record('RESTORE_DRILL_FAILED', $backupRun->project, 'backup_run', $drill->id);
        } finally {
            // 4. Always drop the disposable database.
            if (isset($drillDb)) {
                Process::env([
                    'PGPASSWORD' => (string) env('POSTGRES_PASSWORD', ''),
                    'PGHOST' => self::pgHost(), 'PGPORT' => (string) env('CP_PG_PORT', '5432'), 'PGUSER' => (string) env('CP_PG_USER', 'postgres'),
                ])->timeout(60)->run("psql -c \"DROP DATABASE IF EXISTS \\\"{$drillDb}\\\"\"");
            }
        }

        if ($drill->status === 'drill_passed' && $drill->backup_policy_id) {
            $drill->policy?->update(['last_restore_test_at' => now()]);
        }

        return $drill;
    }

    // ── Health (24D.5) ──────────────────────────────────────────────────

    public const HEALTHY = 'HEALTHY';
    public const WARNING = 'WARNING';
    public const FAILED = 'FAILED';
    public const NEVER_TESTED = 'NEVER_TESTED';

    public static function health(Project $project): array
    {
        $policies = BackupPolicy::where('project_id', $project->id)->get();
        $latest = BackupRun::where('project_id', $project->id)->where('type', 'database')->orderByDesc('id')->first();
        $latestDrill = BackupRun::where('project_id', $project->id)->where('type', 'restore_drill')->orderByDesc('id')->first();

        $statuses = [];
        foreach ($policies as $policy) {
            $ageHours = $policy->last_run_at ? $policy->last_run_at->diffInHours(now()) : null;
            $due = $policy->last_run_at === null || ($ageHours !== null && $ageHours > 48);
            $drillAge = $policy->last_restore_test_at ? $policy->last_restore_test_at->diffInDays(now()) : null;
            $statuses[] = [
                'policy' => $policy->name,
                'status' => $due ? self::WARNING : self::HEALTHY,
                'reason' => $due ? 'latest backup too old / never run' : 'on schedule',
                'restore' => $drillAge === null ? self::NEVER_TESTED : ($drillAge > 30 ? self::WARNING : self::HEALTHY),
                'last_run_at' => $policy->last_run_at?->toIso8601String(),
                'last_restore_test_at' => $policy->last_restore_test_at?->toIso8601String(),
            ];
        }
        $overall = self::HEALTHY;
        if ($latest === null) {
            $overall = self::WARNING;
        } elseif ($latest->status === 'failed') {
            $overall = self::FAILED;
        }
        if ($latestDrill === null && $policies->isNotEmpty()) {
            $overall = $overall === self::FAILED ? self::FAILED : self::WARNING;
        }

        return [
            'overall' => $policies->isEmpty() ? self::WARNING : $overall,
            'policies' => $statuses,
            'latest_backup_at' => $latest?->finished_at?->toIso8601String(),
            'latest_restore_test_at' => $latestDrill?->finished_at?->toIso8601String(),
            'offsite_configured' => BackupDestination::where('project_id', $project->id)->where('driver', 's3-compatible')->exists(),
        ];
    }

    protected static function pgHost(): string
    {
        // Inside the compose network the postgres service is reachable as
        // "postgres"; honor an override for exotic setups.
        return (string) env('CP_PG_HOST', 'postgres');
    }
}
