<?php

namespace Tests\Feature\Phase40;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0 §3/§4 — the Workspace model, membership, and the capability vocabulary.
 */
class WorkspaceModelTest extends TestCase
{
    use RefreshDatabase;

    // ── Vocabulary integrity ───────────────────────────────────────────

    public function test_capability_list_is_flat_unique_strings(): void
    {
        $all = Capability::all();

        $this->assertNotEmpty($all);
        $this->assertSame($all, array_values(array_unique($all)), 'Capability::all() must be unique.');

        foreach ($all as $capability) {
            $this->assertIsString($capability);
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $capability);
        }
    }

    /**
     * The required 0.4.0 vocabulary from the mission brief must exist verbatim.
     */
    public function test_mission_capabilities_exist(): void
    {
        foreach ([
            'projects.create', 'projects.manage',
            'migrations.run', 'migrations.approve',
            'cdc.view', 'cdc.manage',
            'cutover.view', 'cutover.approve',
            'backups.view', 'backups.restore',
            'infrastructure.view', 'infrastructure.manage',
            'ai.use', 'ai.platform', 'ai.workspace', 'ai.project', 'ai.approve_actions',
            'security.view', 'security.manage',
            'audit.view',
        ] as $required) {
            $this->assertTrue(Capability::exists($required), "Missing required capability: {$required}");
        }
    }

    public function test_every_role_references_only_real_capabilities(): void
    {
        // Throws if any role nests a list or names an unknown capability.
        Roles::assertWellFormed();
        $this->assertTrue(true);
    }

    public function test_roles_grant_nothing_when_role_is_null_or_unknown(): void
    {
        foreach (['platform', 'workspace', 'project'] as $scope) {
            $this->assertSame([], Roles::capabilities($scope, null));
            $this->assertSame([], Roles::capabilities($scope, ''));
            $this->assertSame([], Roles::capabilities($scope, 'does_not_exist'));
        }
    }

    public function test_platform_owner_is_the_only_wildcard(): void
    {
        $this->assertSame(['*'], Roles::capabilities('platform', Roles::PLATFORM_OWNER));

        foreach (['workspace', 'project'] as $scope) {
            foreach (Roles::map()[$scope] as $role => $capabilities) {
                $this->assertNotContains('*', $capabilities, "{$scope}/{$role} must not be a wildcard.");
            }
        }
    }

    public function test_privilege_increases_monotonically_across_roles(): void
    {
        $viewer = Roles::capabilities('project', Roles::VIEWER);
        $operator = Roles::capabilities('project', Roles::OPERATOR);
        $developer = Roles::capabilities('project', Roles::DEVELOPER);
        $admin = Roles::capabilities('project', Roles::PROJECT_ADMIN);

        // Every read a viewer has, an operator and above must also have.
        foreach ($viewer as $capability) {
            $this->assertContains($capability, $operator, "Operator lost viewer capability {$capability}");
            $this->assertContains($capability, $developer, "Developer lost viewer capability {$capability}");
            $this->assertContains($capability, $admin, "Project admin lost viewer capability {$capability}");
        }
    }

    public function test_only_project_admin_and_above_hold_approval_capabilities(): void
    {
        foreach ([Roles::VIEWER, Roles::OPERATOR, Roles::DEVELOPER] as $role) {
            $capabilities = Roles::capabilities('project', $role);
            $this->assertNotContains(Capability::CUTOVER_APPROVE, $capabilities, "{$role} must not approve cutover");
            $this->assertNotContains(Capability::MIGRATIONS_APPROVE, $capabilities, "{$role} must not approve migrations");
        }
    }

    // ── Model behaviour ────────────────────────────────────────────────

    public function test_workspace_owns_projects_and_exposes_route_key_as_slug(): void
    {
        $workspace = Workspace::create(['name' => 'Nayrouz', 'slug' => 'nayrouz', 'kind' => 'client']);

        Project::create(['name' => 'Website Production', 'slug' => 'nayrouz-web', 'status' => 'active', 'workspace_id' => $workspace->id]);
        Project::create(['name' => 'Booking Backend', 'slug' => 'nayrouz-booking', 'status' => 'active', 'workspace_id' => $workspace->id]);

        $this->assertSame('slug', $workspace->getRouteKeyName());
        $this->assertSame(2, $workspace->projects()->count());
        $this->assertSame('Client', $workspace->displayKind());
        $this->assertSame('Unknown', $workspace->healthLabel());
    }

    public function test_project_without_workspace_is_valid_and_reported_as_ungrouped(): void
    {
        $project = Project::create(['name' => 'Legacy', 'slug' => 'legacy', 'status' => 'active']);

        $this->assertNull($project->workspace_id);
        $this->assertFalse($project->isGrouped());
        $this->assertNull($project->workspace);
        // Must not throw.
        $this->assertSame('Unknown', $project->healthLabel());
    }

    public function test_workspace_owner_relationship_resolves_through_membership(): void
    {
        $workspace = Workspace::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id,
            'role' => Roles::WORKSPACE_OWNER, 'status' => 'active',
        ]);

        $this->assertTrue($workspace->owner()->is($owner));
        $this->assertSame(1, $workspace->members()->count());
    }

    public function test_membership_pivot_exposes_role_and_status(): void
    {
        $workspace = Workspace::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => Roles::WORKSPACE_ADMIN, 'status' => 'active',
        ]);

        $membership = $user->workspaces()->first()->pivot;
        $this->assertSame(Roles::WORKSPACE_ADMIN, $membership->role);
        $this->assertSame('active', $membership->status);
    }

    public function test_membership_is_unique_per_workspace_and_user(): void
    {
        $workspace = Workspace::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create(['is_admin' => false, 'cp_role' => null]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => Roles::VIEWER, 'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => Roles::WORKSPACE_OWNER, 'status' => 'active',
        ]);
    }

    public function test_initials_helper_handles_names(): void
    {
        $this->assertSame('AM', (new User(['name' => 'Ahmed Makram']))->initials());
        $this->assertSame('N', (new User(['name' => 'Nayrouz']))->initials());
        $this->assertSame('?', (new User(['name' => '   ']))->initials());
    }

    // ── Platform role assignment ───────────────────────────────────────

    public function test_explicit_platform_role_overrides_legacy_cp_role(): void
    {
        // An operator has downgraded this user, but the legacy column still
        // says 'owner'. The explicit platform_role must win.
        $user = User::factory()->create([
            'is_admin' => false, 'cp_role' => 'owner', 'platform_role' => null,
        ]);
        $this->assertTrue(Access::for($user)->isPlatformOwner());

        $user->forceFill(['platform_role' => Roles::PLATFORM_ADMIN])->save();
        $this->assertSame(Roles::PLATFORM_ADMIN, Access::for($user)->platformRole());

        // And a user with no platform role at all is not an owner.
        $plain = User::factory()->create(['is_admin' => false, 'cp_role' => 'observer']);
        $this->assertNull(Access::for($plain)->platformRole());
    }

    public function test_platform_admin_lacks_destructive_platform_capabilities(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'platform_role' => Roles::PLATFORM_ADMIN,
        ]);
        $access = Access::for($admin);

        $this->assertTrue($access->allows(Capability::INFRASTRUCTURE_MANAGE));
        $this->assertTrue($access->allows(Capability::AI_CONFIGURE));

        $this->assertFalse($access->allows(Capability::WORKSPACES_MANAGE));
        $this->assertFalse($access->allows(Capability::PROJECTS_DELETE));
        $this->assertFalse($access->allows(Capability::SETTINGS_MANAGE));
    }

    // ── Project membership status ──────────────────────────────────────

    public function test_inactive_project_membership_does_not_grant(): void
    {
        $project = Project::create(['name' => 'P', 'slug' => 'p1', 'status' => 'active']);
        $user = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'role' => Roles::PROJECT_ADMIN, 'status' => 'invited',
        ]);

        $this->assertFalse(Access::for($user)->allows(Capability::PROJECTS_MANAGE, 'project', null, $project));

        ProjectMember::query()->where('project_id', $project->id)->update(['status' => 'active']);
        $this->assertTrue(Access::for($user)->allows(Capability::PROJECTS_MANAGE, 'project', null, $project));
    }

    public function test_workspace_and_project_roles_combine_additively(): void
    {
        $workspace = Workspace::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $project = Project::create(['name' => 'P', 'slug' => 'p1', 'status' => 'active', 'workspace_id' => $workspace->id]);
        $user = User::factory()->create(['is_admin' => false, 'cp_role' => null]);

        // A narrow workspace role…
        WorkspaceMember::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'role' => Roles::WORKSPACE_MEMBER, 'status' => 'active',
        ]);
        $access = Access::for($user);
        $this->assertFalse($access->allows(Capability::DATA_WRITE, 'project', null, $project));

        // …widened by a project-specific grant on one project.
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'role' => Roles::DEVELOPER, 'status' => 'active',
        ]);
        $this->assertTrue($access->allows(Capability::DATA_WRITE, 'project', null, $project));
    }
}
