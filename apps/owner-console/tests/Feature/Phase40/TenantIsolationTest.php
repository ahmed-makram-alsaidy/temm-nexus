<?php

namespace Tests\Feature\Phase40;

use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 §37 — tenant isolation.
 *
 * These prove the platform's core isolation promise SERVER-SIDE:
 *   "Workspace A user cannot access Workspace B."
 *   "Project A user cannot access Project B unless granted."
 *
 * Nothing here relies on navigation, route hiding, or the UI. Every assertion
 * goes through Access, which is what the pages and the AI tools both call.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
    }

    // ── Workspace enumeration ──────────────────────────────────────────

    public function test_platform_owner_sees_every_workspace_and_project(): void
    {
        $access = Access::for($this->platformOwner);

        $this->assertEqualsCanonicalizing(
            [$this->alpha->id, $this->beta->id],
            $access->accessibleWorkspaceIds(),
        );
        $this->assertEqualsCanonicalizing(
            [$this->alphaWeb->id, $this->alphaBooking->id, $this->betaCrm->id],
            $access->accessibleProjectIds(),
        );
    }

    public function test_workspace_owner_sees_only_their_own_workspace(): void
    {
        $access = Access::for($this->alphaOwner);

        $this->assertSame([$this->alpha->id], $access->accessibleWorkspaceIds());
        $this->assertEqualsCanonicalizing(
            [$this->alphaWeb->id, $this->alphaBooking->id],
            $access->accessibleProjectIds(),
        );
        $this->assertNotContains($this->beta->id, $access->accessibleWorkspaceIds());
        $this->assertNotContains($this->betaCrm->id, $access->accessibleProjectIds());
    }

    public function test_workspace_member_sees_workspace_projects_but_not_other_tenant(): void
    {
        $access = Access::for($this->alphaMember);

        $this->assertSame([$this->alpha->id], $access->accessibleWorkspaceIds());
        $this->assertNotContains($this->betaCrm->id, $access->accessibleProjectIds());
    }

    public function test_project_only_member_sees_exactly_one_project(): void
    {
        $access = Access::for($this->alphaDevOnWeb);

        // No workspace membership at all.
        $this->assertSame([], $access->accessibleWorkspaceIds());
        // Exactly the single project they were granted.
        $this->assertSame([$this->alphaWeb->id], $access->accessibleProjectIds());
    }

    public function test_user_with_no_membership_sees_nothing(): void
    {
        $access = Access::for($this->nobody);

        $this->assertSame([], $access->accessibleWorkspaceIds());
        $this->assertSame([], $access->accessibleProjectIds());
        $this->assertFalse($access->hasPlatformAccess());
    }

    public function test_guest_sees_nothing(): void
    {
        $access = Access::for(null);

        $this->assertSame([], $access->accessibleWorkspaceIds());
        $this->assertSame([], $access->accessibleProjectIds());
        $this->assertFalse($access->isAuthenticated());
    }

    // ── Cross-tenant reach ─────────────────────────────────────────────

    public function test_workspace_a_user_cannot_reach_workspace_b(): void
    {
        $access = Access::for($this->alphaOwner);

        $this->assertTrue($access->canReachWorkspace($this->alpha));
        $this->assertFalse($access->canReachWorkspace($this->beta));
    }

    public function test_workspace_a_user_cannot_reach_workspace_b_projects(): void
    {
        $access = Access::for($this->alphaOwner);

        $this->assertTrue($access->canReachProject($this->alphaWeb));
        $this->assertFalse($access->canReachProject($this->betaCrm));
    }

    public function test_cross_tenant_reach_aborts_404_not_403(): void
    {
        // 404, never 403: a 403 would confirm the other tenant's object exists.
        $access = Access::for($this->alphaOwner);

        try {
            $access->authorizeProjectReach($this->betaCrm);
            $this->fail('Expected a 404 for a cross-tenant project.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $access->authorizeWorkspaceReach($this->beta);
            $this->fail('Expected a 404 for a cross-tenant workspace.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // ── Capability checks do not cross tenants ─────────────────────────

    public function test_workspace_owner_capabilities_do_not_apply_to_other_tenant(): void
    {
        $access = Access::for($this->alphaOwner);

        // Held inside their own workspace…
        $this->assertTrue($access->allows(Capability::PROJECTS_MANAGE, 'workspace', $this->alpha));
        $this->assertTrue($access->allows(Capability::CUTOVER_APPROVE, 'project', null, $this->alphaWeb));

        // …and refused in the other one.
        $this->assertFalse($access->allows(Capability::PROJECTS_MANAGE, 'workspace', $this->beta));
        $this->assertFalse($access->allows(Capability::CUTOVER_APPROVE, 'project', null, $this->betaCrm));
        $this->assertFalse($access->allows(Capability::DATA_READ, 'project', null, $this->betaCrm));
    }

    public function test_project_only_member_cannot_act_on_sibling_project(): void
    {
        $access = Access::for($this->alphaDevOnWeb);

        // Their own project: developer capabilities apply.
        $this->assertTrue($access->allows(Capability::MIGRATIONS_RUN, 'project', null, $this->alphaWeb));
        $this->assertTrue($access->allows(Capability::DATA_WRITE, 'project', null, $this->alphaWeb));

        // The sibling project in the SAME workspace is still out of reach.
        $this->assertFalse($access->allows(Capability::MIGRATIONS_RUN, 'project', null, $this->alphaBooking));
        $this->assertFalse($access->allows(Capability::DATA_VIEW, 'project', null, $this->alphaBooking));
        $this->assertFalse($access->canReachProject($this->alphaBooking));
    }

    public function test_project_viewer_is_read_only_even_inside_their_project(): void
    {
        $access = Access::for($this->alphaViewer);

        $this->assertTrue($access->allows(Capability::PROJECTS_VIEW, 'project', null, $this->alphaBooking));
        $this->assertTrue($access->allows(Capability::CDC_VIEW, 'project', null, $this->alphaBooking));

        foreach ([
            Capability::MIGRATIONS_RUN,
            Capability::MIGRATIONS_APPROVE,
            Capability::CDC_MANAGE,
            Capability::CUTOVER_APPROVE,
            Capability::CUTOVER_EXECUTE,
            Capability::DATA_WRITE,
            Capability::BACKUPS_RESTORE,
            Capability::SECRETS_MANAGE,
            Capability::PROJECTS_MANAGE,
        ] as $capability) {
            $this->assertFalse(
                $access->allows($capability, 'project', null, $this->alphaBooking),
                "Viewer must not hold {$capability}",
            );
        }
    }

    // ── Fail-closed behaviour ──────────────────────────────────────────

    public function test_unknown_capability_never_grants(): void
    {
        // Even a platform owner gets nothing for a capability that does not
        // exist — a typo must not become a wildcard.
        $access = Access::for($this->platformOwner);

        $this->assertFalse($access->allows('not.a.real.capability', 'platform'));
        $this->assertFalse($access->allows('not.a.real.capability'));
        $this->assertFalse($access->allows('', 'platform'));
    }

    public function test_unknown_role_grants_nothing(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'cp_role' => null]);
        WorkspaceMember::create([
            'workspace_id' => $this->alpha->id,
            'user_id' => $user->id,
            'role' => 'superuser_typo',
            'status' => 'active',
        ]);

        $access = Access::for($user);

        $this->assertFalse($access->allows(Capability::PROJECTS_VIEW, 'workspace', $this->alpha));
        $this->assertFalse($access->allows(Capability::PROJECTS_VIEW, 'project', null, $this->alphaWeb));
    }

    public function test_suspended_membership_grants_nothing(): void
    {
        WorkspaceMember::query()
            ->where('workspace_id', $this->alpha->id)
            ->where('user_id', $this->alphaOwner->id)
            ->update(['status' => 'suspended']);

        $access = Access::for($this->alphaOwner);

        $this->assertSame([], $access->accessibleWorkspaceIds());
        $this->assertFalse($access->allows(Capability::PROJECTS_MANAGE, 'workspace', $this->alpha));
    }

    /**
     * §17 — authorisation is evaluated at EXECUTION time, not prompt time.
     * Revoking a grant mid-session must take effect on the very next check.
     */
    public function test_revocation_takes_effect_immediately(): void
    {
        $access = Access::for($this->alphaDevOnWeb);

        $this->assertTrue($access->allows(Capability::MIGRATIONS_RUN, 'project', null, $this->alphaWeb));

        ProjectMember::query()
            ->where('project_id', $this->alphaWeb->id)
            ->where('user_id', $this->alphaDevOnWeb->id)
            ->update(['status' => 'revoked']);

        // Same Access instance, no cache to clear.
        $this->assertFalse($access->allows(Capability::MIGRATIONS_RUN, 'project', null, $this->alphaWeb));
        $this->assertSame([], $access->accessibleProjectIds());
    }

    public function test_mismatched_workspace_argument_cannot_widen_a_project_check(): void
    {
        // Ask about beta's project while claiming alpha owns it.
        $access = Access::for($this->alphaOwner);

        $this->assertFalse(
            $access->allows(Capability::PROJECTS_MANAGE, 'project', $this->alpha, $this->betaCrm),
        );
    }

    // ── Legacy bridge (upgrade safety) ─────────────────────────────────

    public function test_legacy_cp_role_translates_to_platform_or_workspace_authority(): void
    {
        $legacyOwner = User::factory()->create(['is_admin' => false, 'cp_role' => 'owner']);
        $legacyAdmin = User::factory()->create(['is_admin' => false, 'cp_role' => 'admin']);
        $legacyObserver = User::factory()->create(['is_admin' => false, 'cp_role' => 'observer']);

        // A legacy owner keeps full platform reach.
        $this->assertSame(Roles::PLATFORM_OWNER, Access::for($legacyOwner)->platformRole());
        $this->assertTrue(Access::for($legacyOwner)->allows(Capability::SETTINGS_MANAGE));

        // A legacy admin/observer holds NO platform role — its authority comes
        // only from the workspace membership the upgrade migration created.
        $this->assertNull(Access::for($legacyAdmin)->platformRole());
        $this->assertNull(Access::for($legacyObserver)->platformRole());
    }

    public function test_legacy_is_admin_still_implies_platform_owner(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'cp_role' => null]);

        $this->assertTrue(Access::for($admin)->isPlatformOwner());
        $this->assertTrue(Access::for($admin)->allows(Capability::SETTINGS_MANAGE));
    }

    public function test_unassigned_legacy_project_is_reachable_by_platform_owner_only(): void
    {
        $orphan = $this->makeProject('Ungrouped Legacy', 'ungrouped-legacy', null);

        $this->assertNull($orphan->workspace_id);
        $this->assertFalse($orphan->isGrouped());

        // A platform owner still sees it (nothing is lost in the upgrade).
        $this->assertContains($orphan->id, Access::for($this->platformOwner)->accessibleProjectIds());

        // A tenant user must not: it belongs to no workspace they hold.
        $this->assertNotContains($orphan->id, Access::for($this->alphaOwner)->accessibleProjectIds());
        $this->assertFalse(Access::for($this->alphaOwner)->allows(Capability::DATA_VIEW, 'project', null, $orphan));
    }

    // ── Workspace-scope grants do not leak platform scope ──────────────

    public function test_workspace_authority_never_grants_platform_scope(): void
    {
        $access = Access::for($this->alphaOwner);

        foreach ([
            Capability::WORKSPACES_MANAGE,
            Capability::WORKSPACES_CREATE,
            Capability::SETTINGS_MANAGE,
            Capability::TEAM_MANAGE,
            Capability::AI_PLATFORM,
            Capability::INFRASTRUCTURE_MANAGE,
        ] as $capability) {
            $this->assertFalse(
                $access->allows($capability, 'platform'),
                "Workspace owner must not hold platform capability {$capability}",
            );
        }
    }
}
