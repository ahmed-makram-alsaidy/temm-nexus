<?php

namespace App\Services\Access;

/**
 * 0.4.0 capability vocabulary.
 *
 * The ONLY permission currency for new surfaces. Roles are conveniences that
 * expand into these strings (see Roles.php); nothing in 0.4.0 may branch on a
 * role *name* alone. Each capability is checked at execution time against the
 * acting user, the owning workspace, and the owning project.
 *
 * Legacy note — Phase 20V `CpAccess::PERMISSIONS` remains the stored format of
 * the `control_plane_roles.permissions` column. LEGACY_MAP below is the single
 * translation point between the two vocabularies. It is intentionally explicit
 * and one-directional (legacy -> capability) so an old role row can never
 * accidentally grant a capability that did not exist when it was written.
 */
final class Capability
{
    // ── Workspace scope ────────────────────────────────────────────────
    public const WORKSPACES_VIEW = 'workspaces.view';

    public const WORKSPACES_CREATE = 'workspaces.create';

    public const WORKSPACES_MANAGE = 'workspaces.manage';

    public const WORKSPACE_MEMBERS_VIEW = 'workspace.members.view';

    public const WORKSPACE_MEMBERS_MANAGE = 'workspace.members.manage';

    // ── Projects ───────────────────────────────────────────────────────
    public const PROJECTS_VIEW = 'projects.view';

    public const PROJECTS_CREATE = 'projects.create';

    public const PROJECTS_MANAGE = 'projects.manage';

    public const PROJECTS_DELETE = 'projects.delete';

    // ── Migration ──────────────────────────────────────────────────────
    public const MIGRATIONS_VIEW = 'migrations.view';

    public const MIGRATIONS_RUN = 'migrations.run';

    public const MIGRATIONS_APPROVE = 'migrations.approve';

    public const MIGRATIONS_PLAN = 'migrations.plan';

    // ── Live sync (CDC) ────────────────────────────────────────────────
    public const CDC_VIEW = 'cdc.view';

    public const CDC_MANAGE = 'cdc.manage';

    public const CDC_PAUSE = 'cdc.pause';

    public const CDC_RESUME = 'cdc.resume';

    // ── Validation ─────────────────────────────────────────────────────
    public const VALIDATION_VIEW = 'validation.view';

    public const VALIDATION_RUN = 'validation.run';

    // ── Cutover ────────────────────────────────────────────────────────
    public const CUTOVER_VIEW = 'cutover.view';

    public const CUTOVER_PREFLIGHT = 'cutover.preflight';

    public const CUTOVER_APPROVE = 'cutover.approve';

    public const CUTOVER_EXECUTE = 'cutover.execute';

    // ── Backups ────────────────────────────────────────────────────────
    public const BACKUPS_VIEW = 'backups.view';

    public const BACKUPS_CREATE = 'backups.create';

    public const BACKUPS_RESTORE = 'backups.restore';

    public const BACKUPS_MANAGE = 'backups.manage';

    // ── Data surfaces ──────────────────────────────────────────────────
    public const DATA_VIEW = 'data.view';

    public const DATA_READ = 'data.read';

    public const DATA_WRITE = 'data.write';

    // ── Infrastructure ─────────────────────────────────────────────────
    public const INFRASTRUCTURE_VIEW = 'infrastructure.view';

    public const INFRASTRUCTURE_MANAGE = 'infrastructure.manage';

    // ── Connectors ─────────────────────────────────────────────────────
    public const CONNECTORS_VIEW = 'connectors.view';

    public const CONNECTORS_CONNECT = 'connectors.connect';

    public const CONNECTORS_MANAGE = 'connectors.manage';

    // ── Operations ─────────────────────────────────────────────────────
    public const OPERATIONS_VIEW = 'operations.view';

    public const OPERATIONS_MANAGE = 'operations.manage';

    public const JOBS_RETRY = 'jobs.retry';

    public const LOGS_VIEW = 'logs.view';

    // ── Security ───────────────────────────────────────────────────────
    public const SECURITY_VIEW = 'security.view';

    public const SECURITY_MANAGE = 'security.manage';

    public const SECRETS_VIEW = 'secrets.view';

    public const SECRETS_MANAGE = 'secrets.manage';

    // ── Audit ──────────────────────────────────────────────────────────
    public const AUDIT_VIEW = 'audit.view';

    // ── Nexus AI ───────────────────────────────────────────────────────
    public const AI_USE = 'ai.use';

    public const AI_PLATFORM = 'ai.platform';

    public const AI_WORKSPACE = 'ai.workspace';

    public const AI_PROJECT = 'ai.project';

    public const AI_APPROVE_ACTIONS = 'ai.approve_actions';

    public const AI_INSPECT = 'ai.inspect';

    public const AI_CONFIGURE = 'ai.configure';

    // ── Team / platform administration ─────────────────────────────────
    public const TEAM_VIEW = 'team.view';

    public const TEAM_MANAGE = 'team.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    /**
     * Every capability string this release defines, exactly once.
     *
     * Written out explicitly rather than derived by reflection: reflection
     * would also pick up LEGACY_MAP (an array) and silently produce a nested
     * value, and an explicit list makes an accidental rename a test failure
     * instead of a runtime surprise.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        static $all = null;

        if ($all === null) {
            $all = [
                self::WORKSPACES_VIEW, self::WORKSPACES_CREATE, self::WORKSPACES_MANAGE,
                self::WORKSPACE_MEMBERS_VIEW, self::WORKSPACE_MEMBERS_MANAGE,

                self::PROJECTS_VIEW, self::PROJECTS_CREATE, self::PROJECTS_MANAGE, self::PROJECTS_DELETE,

                self::MIGRATIONS_VIEW, self::MIGRATIONS_RUN, self::MIGRATIONS_APPROVE, self::MIGRATIONS_PLAN,

                self::CDC_VIEW, self::CDC_MANAGE, self::CDC_PAUSE, self::CDC_RESUME,

                self::VALIDATION_VIEW, self::VALIDATION_RUN,

                self::CUTOVER_VIEW, self::CUTOVER_PREFLIGHT, self::CUTOVER_APPROVE, self::CUTOVER_EXECUTE,

                self::BACKUPS_VIEW, self::BACKUPS_CREATE, self::BACKUPS_RESTORE, self::BACKUPS_MANAGE,

                self::DATA_VIEW, self::DATA_READ, self::DATA_WRITE,

                self::INFRASTRUCTURE_VIEW, self::INFRASTRUCTURE_MANAGE,

                self::CONNECTORS_VIEW, self::CONNECTORS_CONNECT, self::CONNECTORS_MANAGE,

                self::OPERATIONS_VIEW, self::OPERATIONS_MANAGE, self::JOBS_RETRY, self::LOGS_VIEW,

                self::SECURITY_VIEW, self::SECURITY_MANAGE, self::SECRETS_VIEW, self::SECRETS_MANAGE,

                self::AUDIT_VIEW,

                self::AI_USE, self::AI_PLATFORM, self::AI_WORKSPACE, self::AI_PROJECT,
                self::AI_APPROVE_ACTIONS, self::AI_INSPECT, self::AI_CONFIGURE,

                self::TEAM_VIEW, self::TEAM_MANAGE, self::SETTINGS_MANAGE,
            ];
            $all = array_values(array_unique($all));
            sort($all);
        }

        return $all;
    }

    public static function exists(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /**
     * Legacy Phase 20V / 24 / 25 permission -> 0.4.0 capabilities.
     *
     * Used only when reading a stored `control_plane_roles.permissions` array
     * or a legacy user's role, so an upgraded installation keeps working.
     * Anything not listed here grants nothing.
     *
     * @var array<string, list<string>>
     */
    public const LEGACY_MAP = [
        'projects.view' => [self::PROJECTS_VIEW, self::WORKSPACES_VIEW, self::PROJECTS_CREATE],
        'database.read' => [self::DATA_VIEW, self::DATA_READ, self::OPERATIONS_VIEW],
        'database.write' => [self::DATA_WRITE],
        'sql.execute_read' => [self::DATA_READ],
        'sql.execute_write' => [self::DATA_WRITE],
        'functions.view' => [self::OPERATIONS_VIEW],
        'functions.invoke' => [self::OPERATIONS_MANAGE],
        'functions.deploy' => [self::OPERATIONS_MANAGE],
        'users.manage' => [self::TEAM_MANAGE, self::SECURITY_MANAGE],
        'storage.manage' => [self::DATA_WRITE],
        'secrets.manage' => [self::SECRETS_VIEW, self::SECRETS_MANAGE, self::SECURITY_MANAGE],
        'keys.manage' => [self::SECURITY_MANAGE],
        'webhooks.manage' => [self::OPERATIONS_MANAGE],
        'tasks.manage' => [self::OPERATIONS_MANAGE],
        'backups.trigger' => [self::BACKUPS_VIEW, self::BACKUPS_CREATE],
        'restore.local' => [self::BACKUPS_RESTORE],
        'logs.view' => [self::LOGS_VIEW],
        'settings.manage' => [self::SETTINGS_MANAGE],
        'team.manage' => [self::TEAM_VIEW, self::TEAM_MANAGE],
        'infrastructure.view' => [self::INFRASTRUCTURE_VIEW],
        'infrastructure.manage' => [self::INFRASTRUCTURE_MANAGE],
        'environments.manage' => [self::PROJECTS_MANAGE],
        'migrations.manage' => [
            self::MIGRATIONS_VIEW, self::MIGRATIONS_PLAN, self::MIGRATIONS_RUN,
            self::CDC_VIEW, self::CDC_MANAGE, self::CDC_PAUSE, self::CDC_RESUME,
            self::VALIDATION_VIEW, self::VALIDATION_RUN,
            self::CUTOVER_VIEW, self::CUTOVER_PREFLIGHT, self::CONNECTORS_VIEW,
            self::CONNECTORS_CONNECT,
        ],
        'backups.policy' => [self::BACKUPS_VIEW, self::BACKUPS_MANAGE, self::BACKUPS_CREATE],
        'readiness.acknowledge' => [self::VALIDATION_RUN, self::CUTOVER_VIEW],
        'copilot.run' => [self::AI_USE, self::AI_PROJECT, self::AI_INSPECT],
        'repositories.manage' => [self::PROJECTS_MANAGE],
    ];

    /**
     * Expand a stored legacy permission array into capabilities.
     *
     * @param  list<string>  $legacy
     * @return list<string>
     */
    public static function fromLegacy(array $legacy): array
    {
        if (in_array('*', $legacy, true)) {
            return ['*'];
        }

        $out = [];
        foreach ($legacy as $permission) {
            // A stored value may already be a 0.4.0 capability.
            if (self::exists($permission)) {
                $out[] = $permission;
            }
            foreach (self::LEGACY_MAP[$permission] ?? [] as $capability) {
                $out[] = $capability;
            }
        }

        return array_values(array_unique($out));
    }
}
