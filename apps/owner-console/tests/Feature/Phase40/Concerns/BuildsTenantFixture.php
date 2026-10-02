<?php

namespace Tests\Feature\Phase40\Concerns;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;

/**
 * Two fully separate tenants, so every isolation assertion has a real
 * "other side" to fail against.
 *
 *   Workspace ALPHA (client Alpha)          Workspace BETA (client Beta)
 *     project alpha-web                       project beta-crm
 *     project alpha-booking
 *
 *   platformOwner  — platform_owner, no memberships
 *   alphaOwner     — workspace_owner of ALPHA
 *   alphaAdmin     — workspace_admin of ALPHA
 *   alphaMember    — workspace_member of ALPHA
 *   alphaDevOnWeb  — PROJECT-ONLY developer on alpha-web (no workspace role)
 *   alphaViewer    — PROJECT-ONLY viewer on alpha-booking
 *   betaOwner      — workspace_owner of BETA
 *   nobody         — no role, no membership anywhere
 */
trait BuildsTenantFixture
{
    protected Workspace $alpha;

    protected Workspace $beta;

    protected Project $alphaWeb;

    protected Project $alphaBooking;

    protected Project $betaCrm;

    protected User $platformOwner;

    protected User $alphaOwner;

    protected User $alphaAdmin;

    protected User $alphaMember;

    protected User $alphaDevOnWeb;

    protected User $alphaViewer;

    protected User $betaOwner;

    protected User $nobody;

    protected function buildTenantFixture(): void
    {
        $this->alpha = Workspace::create([
            'name' => 'Alpha Client', 'slug' => 'alpha', 'kind' => 'client', 'status' => 'active',
        ]);
        $this->beta = Workspace::create([
            'name' => 'Beta Client', 'slug' => 'beta', 'kind' => 'client', 'status' => 'active',
        ]);

        $this->alphaWeb = $this->makeProject('Alpha Website', 'alpha-web', $this->alpha);
        $this->alphaBooking = $this->makeProject('Alpha Booking', 'alpha-booking', $this->alpha);
        $this->betaCrm = $this->makeProject('Beta CRM', 'beta-crm', $this->beta);

        $this->platformOwner = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'platform_role' => Roles::PLATFORM_OWNER,
        ]);

        $this->alphaOwner = $this->member($this->alpha, Roles::WORKSPACE_OWNER, 'alpha-owner@example.test');
        $this->alphaAdmin = $this->member($this->alpha, Roles::WORKSPACE_ADMIN, 'alpha-admin@example.test');
        $this->alphaMember = $this->member($this->alpha, Roles::WORKSPACE_MEMBER, 'alpha-member@example.test');
        $this->betaOwner = $this->member($this->beta, Roles::WORKSPACE_OWNER, 'beta-owner@example.test');

        // Project-only grants: no workspace membership at all.
        $this->alphaDevOnWeb = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'email' => 'alpha-dev@example.test',
        ]);
        ProjectMember::create([
            'project_id' => $this->alphaWeb->id,
            'user_id' => $this->alphaDevOnWeb->id,
            'role' => Roles::DEVELOPER,
            'status' => 'active',
            'granted_at' => now(),
        ]);

        $this->alphaViewer = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'email' => 'alpha-viewer@example.test',
        ]);
        ProjectMember::create([
            'project_id' => $this->alphaBooking->id,
            'user_id' => $this->alphaViewer->id,
            'role' => Roles::VIEWER,
            'status' => 'active',
            'granted_at' => now(),
        ]);

        $this->nobody = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'email' => 'nobody@example.test',
        ]);
    }

    protected function makeProject(string $name, string $slug, ?Workspace $workspace): Project
    {
        return Project::create([
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'workspace_id' => $workspace?->id,
        ]);
    }

    protected function member(Workspace $workspace, string $role, string $email): User
    {
        $user = User::factory()->create([
            'is_admin' => false, 'cp_role' => null, 'email' => $email,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
