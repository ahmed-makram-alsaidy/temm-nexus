<?php

namespace App\Services\Access;

/**
 * Role definitions for every scope.
 *
 * A role is *only* a named bundle of capabilities. Nothing in the product may
 * authorise on a role name; `Access` always expands the role and compares
 * capabilities. This keeps role names free to change without silently
 * widening access.
 *
 * Scopes
 *   PLATFORM   — cross-workspace. Assigned on `users.platform_role`.
 *   WORKSPACE  — one client/company boundary. `workspace_members.role`.
 *   PROJECT    — one project. `project_members.role`.
 *
 * Roles nest downwards: a workspace role grants its capabilities on every
 * project inside that workspace unless the project grants something narrower.
 * A project membership never grants anything outside that project.
 */
final class Roles
{
    // ── Platform ───────────────────────────────────────────────────────
    public const PLATFORM_OWNER = 'platform_owner';

    public const PLATFORM_ADMIN = 'platform_admin';

    // ── Workspace ──────────────────────────────────────────────────────
    public const WORKSPACE_OWNER = 'workspace_owner';

    public const WORKSPACE_ADMIN = 'workspace_admin';

    public const WORKSPACE_MEMBER = 'workspace_member';

    // ── Project ────────────────────────────────────────────────────────
    public const PROJECT_ADMIN = 'project_admin';

    public const DEVELOPER = 'developer';

    public const OPERATOR = 'operator';

    public const VIEWER = 'viewer';

    /**
     * Capabilities granted on ANY project or workspace, at every scope.
     *
     * DELIBERATELY NARROW. This is the floor a Viewer holds, so it must contain
     * only reads of the user's OWN objects. Platform-wide telemetry is NOT here:
     * a Viewer of one project must not be able to enumerate the installation's
     * failed jobs (`OPERATIONS_VIEW`), its nodes (`INFRASTRUCTURE_VIEW`), or its
     * audit ledger (`AUDIT_VIEW`). Those are added explicitly to the roles that
     * need them, which also makes the AI tool boundary correct by construction:
     * `NexusCopilotTest` asserts a project viewer is not offered them.
     */
    public const READ_ONLY = [
        Capability::WORKSPACES_VIEW,
        Capability::PROJECTS_VIEW,
        Capability::MIGRATIONS_VIEW,
        Capability::CDC_VIEW,
        Capability::VALIDATION_VIEW,
        Capability::CUTOVER_VIEW,
        Capability::BACKUPS_VIEW,
        Capability::DATA_VIEW,
        Capability::CONNECTORS_VIEW,
        Capability::LOGS_VIEW,
        Capability::SECURITY_VIEW,
        Capability::AI_USE,
    ];

    /** Platform-wide telemetry. Explicit on admin roles only. */
    private const PLATFORM_TELEMETRY = [
        Capability::OPERATIONS_VIEW,
        Capability::AUDIT_VIEW,
    ];

    /**
     * Role -> capabilities for each scope.
     *
     * @return array{platform: array<string, list<string>>, workspace: array<string, list<string>>, project: array<string, list<string>>}
     */
    public static function map(): array
    {
        return [
            'platform' => [
                self::PLATFORM_OWNER => ['*'],
                // Everything except destroying the platform's own boundaries.
                self::PLATFORM_ADMIN => array_values(array_diff(Capability::all(), [
                    Capability::WORKSPACES_MANAGE,
                    Capability::PROJECTS_DELETE,
                    Capability::SETTINGS_MANAGE,
                ])),
            ],

            'workspace' => [
                self::WORKSPACE_OWNER => array_merge(self::READ_ONLY, self::PLATFORM_TELEMETRY, [
                    Capability::INFRASTRUCTURE_VIEW,
                    Capability::WORKSPACES_MANAGE,
                    Capability::WORKSPACE_MEMBERS_VIEW,
                    Capability::WORKSPACE_MEMBERS_MANAGE,
                    Capability::PROJECTS_CREATE,
                    Capability::PROJECTS_MANAGE,
                    Capability::PROJECTS_DELETE,
                    Capability::MIGRATIONS_PLAN,
                    Capability::MIGRATIONS_RUN,
                    Capability::MIGRATIONS_APPROVE,
                    Capability::CDC_MANAGE,
                    Capability::CDC_PAUSE,
                    Capability::CDC_RESUME,
                    Capability::VALIDATION_RUN,
                    Capability::CUTOVER_PREFLIGHT,
                    Capability::CUTOVER_APPROVE,
                    Capability::CUTOVER_EXECUTE,
                    Capability::BACKUPS_CREATE,
                    Capability::BACKUPS_MANAGE,
                    Capability::BACKUPS_RESTORE,
                    Capability::DATA_READ,
                    Capability::DATA_WRITE,
                    Capability::INFRASTRUCTURE_MANAGE,
                    Capability::CONNECTORS_CONNECT,
                    Capability::CONNECTORS_MANAGE,
                    Capability::OPERATIONS_MANAGE,
                    Capability::JOBS_RETRY,
                    Capability::SECURITY_MANAGE,
                    Capability::SECRETS_VIEW,
                    Capability::SECRETS_MANAGE,
                    Capability::AI_WORKSPACE,
                    Capability::AI_PROJECT,
                    Capability::AI_INSPECT,
                    Capability::AI_APPROVE_ACTIONS,
                    Capability::AGENTS_VIEW,
                    Capability::AGENTS_RUN,
                    Capability::AGENTS_CANCEL,
                    Capability::AGENTS_APPROVE,
                    Capability::AGENTS_APPLY,
                    Capability::AGENTS_AUDIT,
                ]),

                // Runs day-to-day work but cannot approve cutover or see secrets.
                self::WORKSPACE_ADMIN => array_merge(self::READ_ONLY, self::PLATFORM_TELEMETRY, [
                    Capability::INFRASTRUCTURE_VIEW,
                    Capability::WORKSPACE_MEMBERS_VIEW,
                    Capability::PROJECTS_CREATE,
                    Capability::PROJECTS_MANAGE,
                    Capability::MIGRATIONS_PLAN,
                    Capability::MIGRATIONS_RUN,
                    Capability::CDC_MANAGE,
                    Capability::CDC_PAUSE,
                    Capability::CDC_RESUME,
                    Capability::VALIDATION_RUN,
                    Capability::CUTOVER_PREFLIGHT,
                    Capability::BACKUPS_CREATE,
                    Capability::BACKUPS_MANAGE,
                    Capability::DATA_READ,
                    Capability::CONNECTORS_CONNECT,
                    Capability::OPERATIONS_MANAGE,
                    Capability::JOBS_RETRY,
                    Capability::SECRETS_VIEW,
                    Capability::AI_WORKSPACE,
                    Capability::AI_PROJECT,
                    Capability::AI_INSPECT,
                    Capability::AI_APPROVE_ACTIONS,
                    Capability::AGENTS_VIEW,
                    Capability::AGENTS_RUN,
                    Capability::AGENTS_CANCEL,
                    Capability::AGENTS_APPROVE,
                    Capability::AGENTS_APPLY,
                    Capability::AGENTS_AUDIT,
                ]),

                // Default for a client contact: read the workspace, nothing more.
                self::WORKSPACE_MEMBER => array_merge(self::READ_ONLY, [
                    Capability::WORKSPACE_MEMBERS_VIEW,
                    Capability::AI_WORKSPACE,
                    Capability::AI_PROJECT,
                    Capability::AGENTS_VIEW,
                ]),
            ],

            'project' => [
                self::PROJECT_ADMIN => array_merge(self::READ_ONLY, self::PLATFORM_TELEMETRY, [
                    Capability::INFRASTRUCTURE_VIEW,
                    Capability::PROJECTS_MANAGE,
                    Capability::MIGRATIONS_PLAN,
                    Capability::MIGRATIONS_RUN,
                    Capability::CDC_MANAGE,
                    Capability::CDC_PAUSE,
                    Capability::CDC_RESUME,
                    Capability::VALIDATION_RUN,
                    Capability::CUTOVER_PREFLIGHT,
                    Capability::BACKUPS_CREATE,
                    Capability::BACKUPS_MANAGE,
                    Capability::DATA_READ,
                    Capability::CONNECTORS_CONNECT,
                    Capability::OPERATIONS_MANAGE,
                    Capability::JOBS_RETRY,
                    Capability::SECRETS_VIEW,
                    Capability::AI_PROJECT,
                    Capability::AI_INSPECT,
                    Capability::AI_APPROVE_ACTIONS,
                    Capability::AGENTS_VIEW,
                    Capability::AGENTS_RUN,
                    Capability::AGENTS_CANCEL,
                    Capability::AGENTS_APPROVE,
                    Capability::AGENTS_APPLY,
                    Capability::AGENTS_AUDIT,
                ]),

                // Writes code/schema and runs migrations. No approval rights.
                self::DEVELOPER => array_merge(self::READ_ONLY, [
                    Capability::MIGRATIONS_PLAN,
                    Capability::MIGRATIONS_RUN,
                    Capability::VALIDATION_RUN,
                    Capability::DATA_READ,
                    Capability::DATA_WRITE,
                    Capability::CONNECTORS_CONNECT,
                    Capability::OPERATIONS_MANAGE,
                    Capability::JOBS_RETRY,
                    Capability::AI_PROJECT,
                    Capability::AI_INSPECT,
                    Capability::AGENTS_VIEW,
                    Capability::AGENTS_RUN,
                ]),

                // Runs operations and Live Sync. No schema or code changes.
                self::OPERATOR => array_merge(self::READ_ONLY, [
                    Capability::CDC_MANAGE,
                    Capability::CDC_PAUSE,
                    Capability::CDC_RESUME,
                    Capability::VALIDATION_RUN,
                    Capability::BACKUPS_CREATE,
                    Capability::JOBS_RETRY,
                    Capability::DATA_READ,
                    Capability::AI_PROJECT,
                    Capability::AGENTS_VIEW,
                ]),

                self::VIEWER => array_merge(self::READ_ONLY, [
                    Capability::AI_PROJECT,
                    Capability::AGENTS_VIEW,
                ]),
            ],
        ];
    }

    /**
     * Guard against a capability list accidentally nesting (which would make a
     * comparison silently always-false, or worse, coerce to a wildcard).
     * Called by the Phase 40 test suite; cheap enough to keep in production.
     */
    public static function assertWellFormed(): void
    {
        foreach (self::map() as $scope => $roles) {
            foreach ($roles as $role => $capabilities) {
                foreach ($capabilities as $capability) {
                    if (! is_string($capability)) {
                        throw new \RuntimeException(
                            "Role {$scope}/{$role} contains a non-string capability (".gettype($capability).').'
                        );
                    }
                    if ($capability !== '*' && ! Capability::exists($capability)) {
                        throw new \RuntimeException(
                            "Role {$scope}/{$role} references unknown capability '{$capability}'."
                        );
                    }
                }
            }
        }

        foreach (Capability::all() as $capability) {
            if (! is_string($capability)) {
                throw new \RuntimeException('Capability::all() returned a non-string entry.');
            }
        }
    }

    /** Capabilities for a role at a scope. Unknown role => no capabilities. */
    public static function capabilities(string $scope, ?string $role): array
    {
        if ($role === null || $role === '') {
            return [];
        }

        $capabilities = self::map()[$scope][$role] ?? [];

        // Defensive: never hand a nested array to a comparison.
        return array_values(array_filter($capabilities, 'is_string'));
    }

    public static function existsAt(string $scope, ?string $role): bool
    {
        return $role !== null && isset(self::map()[$scope][$role]);
    }

    /** @return list<string> */
    public static function platformRoles(): array
    {
        return array_keys(self::map()['platform']);
    }

    /** @return list<string> */
    public static function workspaceRoles(): array
    {
        return array_keys(self::map()['workspace']);
    }

    /** @return list<string> */
    public static function projectRoles(): array
    {
        return array_keys(self::map()['project']);
    }

    /**
     * Map a legacy Phase 20V `cp_role` to (scope, role).
     *
     * Used once during the 0.4.0 upgrade to translate existing users, and as a
     * compatibility fallback while an installation has not been re-assigned.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromLegacy(string $cpRole): array
    {
        return match ($cpRole) {
            'owner' => ['platform', self::PLATFORM_OWNER],
            'admin' => ['workspace', self::WORKSPACE_OWNER],
            'developer' => ['workspace', self::WORKSPACE_ADMIN],
            'observer' => ['workspace', self::WORKSPACE_MEMBER],
            default => ['project', self::VIEWER],
        };
    }

    /** Human label for UI. */
    public static function label(string $role): string
    {
        return match ($role) {
            self::PLATFORM_OWNER => 'Platform Owner',
            self::PLATFORM_ADMIN => 'Platform Admin',
            self::WORKSPACE_OWNER => 'Workspace Owner',
            self::WORKSPACE_ADMIN => 'Workspace Admin',
            self::WORKSPACE_MEMBER => 'Workspace Member',
            self::PROJECT_ADMIN => 'Project Admin',
            self::DEVELOPER => 'Developer',
            self::OPERATOR => 'Operator',
            self::VIEWER => 'Viewer',
            default => ucfirst(str_replace('_', ' ', $role)),
        };
    }
}
