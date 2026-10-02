<?php

namespace App\Services\Ai\Actions;

use App\Models\MigrationRun;
use App\Models\Project;
use App\Services\Access\Capability;
use App\Services\Ai\Scope;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use App\Services\ControlPlane\ProjectArtisan;
use App\Services\ControlPlane\ProjectBackupService;
use App\Services\ControlPlane\ReadinessService;

/**
 * 0.4.0 Phase J — the ACTION registry, deliberately separate from the READ
 * registry (`ToolRegistry`).
 *
 * THE SEPARATION IS THE SECURITY PROPERTY
 * `ToolRegistry` is read-only by construction and the read dispatcher REFUSES
 * anything not marked `read_only`. This registry holds every mutating tool
 * the AI can reach. There is no third list: an action is either a platform
 * feature behind its own UI, or an entry here, or it does not exist for the
 * AI.
 *
 * Every action declares (J.1): scope, required capability, risk level, an
 * argument schema — all plain data, validated by tests — plus its behaviour
 * through the typed methods below (intent / affected / fingerprint /
 * execute / verify). There is deliberately NO generic "run command" entry
 * point and no dynamic dispatch: a model can only ever name one of these keys.
 *
 * PHASE J ALLOWLIST (J.2) — LOW and MODERATE only. Deliberately ABSENT:
 * execute_sql, run_shell, run_command, edit_env, change_dns, drop_database,
 * delete_project, restore_backup, perform_cutover, rotate_credentials,
 * modify_firewall, modify_caddy, install_package. Those are HIGH/PROHIBITED
 * and require a separate design; code modification is NOT an action tool at
 * all (J.16) — it stays in the isolated patch workflow.
 */
final class ActionRegistry
{
    /**
     * Plain data only — class constants cannot hold closures, and keeping the
     * declarations scalar is what makes the registry testable as a contract.
     *
     * @var array<string, array{
     *     scope: Scope, capability: string, risk: string,
     *     description: string, label: string,
     *     parameters: array<string, array{type: string, required: bool, description: string}>,
     * }>
     */
    public const ACTIONS = [
        'pause_cdc' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::CDC_PAUSE,
            'risk' => 'moderate',
            'label' => 'Pausing Live Sync',
            'description' => 'Pause this project\'s Live Sync stream. Changes keep accumulating at the source and can be resumed.',
            'parameters' => [
                'reason' => ['type' => 'string', 'required' => false, 'description' => 'Why the stream is being paused.'],
            ],
        ],

        'resume_cdc' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::CDC_RESUME,
            'risk' => 'moderate',
            'label' => 'Resuming Live Sync',
            'description' => 'Resume this project\'s paused Live Sync stream from its last checkpoint.',
            'parameters' => [
                'reason' => ['type' => 'string', 'required' => false, 'description' => 'Why the stream is being resumed.'],
            ],
        ],

        'create_backup' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::BACKUPS_CREATE,
            'risk' => 'moderate',
            'label' => 'Creating a backup',
            'description' => 'Trigger a database backup for this project and verify the dump was written.',
            'parameters' => [],
        ],

        'rerun_validation' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::VALIDATION_RUN,
            'risk' => 'low',
            'label' => 'Re-running validation',
            'description' => 'Re-evaluate this project\'s readiness checks. Read-only diagnostics; changes nothing in your systems.',
            'parameters' => [],
        ],

        'retry_failed_job' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::JOBS_RETRY,
            'risk' => 'low',
            'label' => 'Retrying a failed job',
            'description' => 'Push one failed queue job back onto the queue, addressed by its UUID.',
            'parameters' => [
                'uuid' => ['type' => 'string', 'required' => true, 'description' => 'The failed job\'s UUID.'],
            ],
        ],

        'request_cutover_preflight' => [
            'scope' => Scope::PROJECT,
            'capability' => Capability::CUTOVER_PREFLIGHT,
            'risk' => 'low',
            'label' => 'Requesting a cutover preflight',
            'description' => 'Record a fresh cutover plan from current evidence. Nothing is executed and no system is touched.',
            'parameters' => [],
        ],
    ];

    /** Risk levels that may be proposed through the AI at all in Phase J. */
    public const PROPOSABLE_RISKS = ['low', 'moderate'];

    public static function exists(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $action): ?array
    {
        return self::ACTIONS[$action] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ACTIONS);
    }

    /**
     * Validate arguments against the declared schema: no unknown keys, required
     * keys present, scalar strings only. Returns the SANITIZED argument map or
     * null when the arguments are unacceptable.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>|null
     */
    public static function sanitizeArguments(string $action, array $arguments): ?array
    {
        $definition = self::definition($action);
        if ($definition === null) {
            return null;
        }

        $clean = [];
        foreach ($arguments as $name => $value) {
            if (! isset($definition['parameters'][$name]) || ! is_string($value)) {
                return null;
            }
            // Length cap: an argument is a selector or a reason, never a payload.
            $clean[$name] = mb_substr($value, 0, 300);
        }

        foreach ($definition['parameters'] as $name => $spec) {
            if ($spec['required'] && ! isset($clean[$name])) {
                return null;
            }
            $clean[$name] ??= '';
        }

        return $clean;
    }

    // ── Behaviour (one typed method per plan field) ────────────────────

    /** The human-readable what-and-why, rendered into the approval card. */
    public static function intentFor(string $action, Project $project, array $arguments): string
    {
        $reason = ($arguments['reason'] ?? '') !== '' ? ' — '.$arguments['reason'] : '';

        return match ($action) {
            'pause_cdc' => 'Pause the Live Sync stream for '.$project->name.$reason.'.',
            'resume_cdc' => 'Resume the Live Sync stream for '.$project->name.$reason.'.',
            'create_backup' => 'Create a database backup for '.$project->name.'.',
            'rerun_validation' => 'Re-run the readiness validation checks for '.$project->name.'.',
            'retry_failed_job' => 'Retry the failed queue job '.($arguments['uuid'] ?? '').' for '.$project->name.'.',
            'request_cutover_preflight' => 'Record a fresh cutover preflight plan for '.$project->name.'.',
            default => 'Perform '.$action.' on '.$project->name.'.',
        };
    }

    /** Safe descriptors of what the action touches — never credentials. */
    public static function affectedFor(string $action, Project $project, array $arguments): array
    {
        return match ($action) {
            'pause_cdc', 'resume_cdc' => [['resource' => 'cdc_stream', 'project' => $project->name]],
            'create_backup' => [['resource' => 'database_backup', 'project' => $project->name]],
            'rerun_validation' => [['resource' => 'readiness_checks', 'project' => $project->name]],
            'retry_failed_job' => [['resource' => 'queue_job', 'id' => (string) ($arguments['uuid'] ?? '')]],
            'request_cutover_preflight' => [['resource' => 'cutover_plan', 'project' => $project->name]],
            default => [['resource' => $action, 'project' => $project->name]],
        };
    }

    /**
     * The state the plan is built on — where staleness matters (J.7). Null
     * for actions whose world cannot shift between propose and apply.
     */
    public static function fingerprintFor(string $action, Project $project, array $arguments): ?string
    {
        return match ($action) {
            'pause_cdc', 'resume_cdc' => self::streamsFingerprint($project),
            default => null,
        };
    }

    /** Execute the registered handler for an APPROVED plan. */
    public static function execute(string $action, Project $project, array $arguments): array
    {
        return match ($action) {
            'pause_cdc' => self::setStreamStatus($project, 'paused'),
            'resume_cdc' => self::setStreamStatus($project, 'streaming'),
            'create_backup' => self::createBackup($project),
            'rerun_validation' => self::rerunValidation($project),
            'retry_failed_job' => self::retryFailedJob($project, $arguments),
            'request_cutover_preflight' => self::cutoverPreflight($project),
            default => throw new \LogicException('No handler for action '.$action.'.'),
        };
    }

    /** Verify an executed action against the real state (J.11). */
    public static function verify(string $action, Project $project, array $arguments, array $result): array
    {
        return match ($action) {
            'pause_cdc' => self::verifyStreamStatus($project, 'paused'),
            'resume_cdc' => self::verifyStreamStatus($project, 'streaming'),
            'create_backup' => self::verifyBackup($project),
            'rerun_validation' => self::verifyValidation($project),
            'retry_failed_job' => [
                'verified' => (bool) ($result['ok'] ?? false),
                'detail' => (string) ($result['detail'] ?? 'retry command result'),
            ],
            'request_cutover_preflight' => self::verifyPreflight($result),
            default => ['verified' => false, 'detail' => 'No verifier for '.$action.'.'],
        };
    }

    // ── Stream helpers ─────────────────────────────────────────────────

    /** The project's newest CDC checkpoint. */
    public static function projectCheckpoint(Project $project): ?CdcCheckpoint
    {
        $runIds = MigrationRun::query()
            ->whereHas('plan', fn ($q) => $q->where('project_id', $project->getKey()))
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return null;
        }

        return CdcCheckpoint::query()
            ->whereIn('migration_run_id', $runIds)
            ->orderByDesc('id')
            ->first();
    }

    /** "checkpoint#id:status" — a plan executed against a vanished or changed stream refuses. */
    public static function streamsFingerprint(Project $project): string
    {
        $checkpoint = self::projectCheckpoint($project);

        return $checkpoint === null
            ? 'stream:none'
            : 'stream:'.$checkpoint->getKey().':'.strtolower((string) $checkpoint->stream_status);
    }

    private static function setStreamStatus(Project $project, string $status): array
    {
        $checkpoint = self::projectCheckpoint($project);

        if ($checkpoint === null) {
            throw new \RuntimeException('This project has no Live Sync stream to change.');
        }

        $current = strtolower((string) $checkpoint->stream_status);

        if ($current === $status) {
            // Idempotent by design: a double-applied pause/resume is a note,
            // never a second mutation.
            return [
                'ok' => true,
                'stream_status' => $current,
                'detail' => 'The stream was already '.$status.'; nothing was changed.',
                'idempotent' => true,
            ];
        }

        $allowedFrom = $status === 'paused'
            ? ['streaming', 'active', 'lagging', 'stale', 'degraded']
            : ['paused', 'failed', 'error'];

        if (! in_array($current, $allowedFrom, true)) {
            throw new \RuntimeException(
                'The stream is '.$current.', which cannot be set to '.$status.' from here.'
            );
        }

        $checkpoint->update(['stream_status' => $status]);

        return [
            'ok' => true,
            'stream_status' => $status,
            'previous' => $current,
            'checkpoint_id' => (int) $checkpoint->getKey(),
            'detail' => 'Stream status set from '.$current.' to '.$status.'.',
        ];
    }

    private static function verifyStreamStatus(Project $project, string $expected): array
    {
        $checkpoint = self::projectCheckpoint($project);
        $actual = $checkpoint !== null ? strtolower((string) $checkpoint->stream_status) : null;

        return [
            'verified' => $actual === $expected,
            'detail' => $actual === $expected
                ? 'Stream status is '.$actual.'.'
                : 'Stream status is '.($actual ?? 'absent').', expected '.$expected.'.',
        ];
    }

    // ── Backup / validation / queue / cutover handlers ─────────────────

    private static function createBackup(Project $project): array
    {
        $record = ProjectBackupService::for($project)->trigger('full');

        return [
            'ok' => true,
            'backup_id' => $record->getKey(),
            'detail' => 'Backup recorded ('.($record->status ?? 'unknown').').',
        ];
    }

    private static function verifyBackup(Project $project): array
    {
        $record = \App\Models\BackupRecord::query()
            ->where('db_name', $project->db_name)
            ->where('status', 'ok')
            ->orderByDesc('id')
            ->first();

        return [
            'verified' => $record !== null && $record->finished_at !== null && $record->finished_at->isToday(),
            'detail' => $record !== null
                ? 'Latest backup record #'.$record->getKey().' ('.$record->status.').'
                : 'No backup record was written.',
        ];
    }

    private static function rerunValidation(Project $project): array
    {
        $checks = ReadinessService::evaluate($project, EnvironmentContext::active($project));

        return [
            'ok' => true,
            'checks' => count($checks),
            'detail' => count($checks).' readiness check(s) re-evaluated.',
        ];
    }

    private static function verifyValidation(Project $project): array
    {
        $latest = \App\Models\ReadinessCheck::query()
            ->where('project_id', $project->getKey())
            ->max('updated_at');

        return [
            'verified' => $latest !== null,
            'detail' => $latest !== null
                ? 'Readiness checks present, last updated '.$latest.'.'
                : 'No readiness checks were recorded.',
        ];
    }

    private static function retryFailedJob(Project $project, array $arguments): array
    {
        $uuid = (string) ($arguments['uuid'] ?? '');
        if ($uuid === '' || ! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            throw new \RuntimeException('A failed-job UUID is required.');
        }

        $result = ProjectArtisan::run($project, 'queue:retry', [$uuid]);

        if (! ($result['ok'] ?? false)) {
            throw new \RuntimeException('The retry command did not succeed.');
        }

        return [
            'ok' => true,
            'uuid' => $uuid,
            'detail' => 'Job '.$uuid.' pushed back onto the queue.',
        ];
    }

    private static function cutoverPreflight(Project $project): array
    {
        $plan = app(CutoverCenterService::class)->createPlan($project);

        return [
            'ok' => true,
            'cutover_plan_id' => $plan->getKey(),
            'detail' => 'Cutover preflight plan recorded from current evidence.',
        ];
    }

    private static function verifyPreflight(array $result): array
    {
        $planId = $result['cutover_plan_id'] ?? null;
        $exists = $planId !== null
            && \App\Models\CutoverPlan::query()->whereKey($planId)->exists();

        return [
            'verified' => $exists,
            'detail' => $exists
                ? 'Cutover plan #'.$planId.' is on record.'
                : 'No cutover plan was recorded.',
        ];
    }

    /**
     * Defence in depth for the audit ledger: replace any argument whose NAME
     * is secret-shaped before it can be written to audit metadata. Values are
     * already whitelisted typed strings; this guards the ledger, not the model.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function redactArguments(array $arguments): array
    {
        $forbidden = [
            'password', 'password_hash', 'secret', 'secret_encrypted', 'secret_ref',
            'api_key', 'token', 'access_token', 'refresh_token', 'private_key',
            'connection_string', 'dsn', 'credentials', 'authorization',
        ];

        foreach ($arguments as $name => $value) {
            if (is_string($name) && in_array(strtolower($name), $forbidden, true)) {
                $arguments[$name] = '[redacted]';
            }
        }

        return $arguments;
    }
}
