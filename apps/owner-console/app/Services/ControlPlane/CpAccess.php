<?php

namespace App\Services\ControlPlane;

use App\Models\ControlPlaneRole;
use App\Models\User;

/**
 * Phase 20V team RBAC. Permission vocabulary for control-plane surfaces.
 * Infrastructure owners (is_admin / cp_role=owner) bypass every check.
 * Legacy is_admin gates on pre-20 pages are unchanged; new studio surfaces
 * check these granular permissions instead of blanket admin.
 */
class CpAccess
{
    public const PERMISSIONS = [
        'projects.view',
        'database.read',
        'database.write',      // DDL + record writes via studio tools
        'sql.execute_read',
        'sql.execute_write',   // write-mode SQL
        'functions.view',
        'functions.invoke',    // tester + invocation endpoints
        'functions.deploy',    // create/update versions, deploy, rollback
        'users.manage',
        'storage.manage',
        'secrets.manage',
        'keys.manage',
        'webhooks.manage',
        'tasks.manage',
        'backups.trigger',
        'restore.local',       // local/test restore wizard only
        'logs.view',
        'settings.manage',
        'team.manage',
        'infrastructure.view',
        'infrastructure.manage',
        // Phase 24 productization surfaces.
        'environments.manage',   // create/switch/promote project environments
        'migrations.manage',     // migration sources, analyses, plans, runs
        'backups.policy',        // backup policies, destinations, drills
        'readiness.acknowledge', // manual readiness acknowledgements + snapshots
        // Phase 25 AI migration surfaces.
        'copilot.run',           // run the AI Migration Copilot (advisor/builder/validator)
        'repositories.manage',   // link client repositories, approve/apply patches
    ];

    /** Default role → permissions (seeded into control_plane_roles). */
    public static function defaultRoles(): array
    {
        $read = ['projects.view', 'database.read', 'sql.execute_read', 'logs.view', 'functions.view', 'infrastructure.view'];

        return [
            'owner' => ['*'],
            'admin' => array_values(array_diff(self::PERMISSIONS, ['team.manage'])),
            'developer' => array_merge($read, [
                'functions.invoke', 'tasks.manage', 'webhooks.manage', 'storage.manage',
                'migrations.manage',
            ]),
            'observer' => $read,
        ];
    }

    public static function seedDefaults(): void
    {
        foreach (self::defaultRoles() as $name => $permissions) {
            ControlPlaneRole::updateOrCreate(['name' => $name], ['permissions' => $permissions]);
        }
    }

    public static function rolePermissions(?User $user): array
    {
        if (! $user) {
            return [];
        }
        // Fail-closed: only is_admin or an explicitly assigned 'owner' role
        // bypasses. NULL/unassigned cp_role grants nothing.
        if ((bool) ($user->is_admin ?? false) || ($user->cp_role ?? '') === 'owner') {
            return ['*'];
        }
        try {
            $role = ControlPlaneRole::query()->where('name', $user->cp_role)->first();
            if ($role) {
                return $role->permissions ?? [];
            }
        } catch (\Throwable) {
            // Table missing (pre-migration): deny everything non-owner.
        }

        return self::defaultRoles()[$user->cp_role] ?? [];
    }

    public static function allows(?User $user, string $permission): bool
    {
        $perms = self::rolePermissions($user);

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function require(?User $user, string $permission): void
    {
        abort_unless(self::allows($user, $permission), 403, 'Missing control-plane permission: '.$permission);
    }
}
