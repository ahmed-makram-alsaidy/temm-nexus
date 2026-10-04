<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentRuntime;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Capability boundaries for the agents.* vocabulary, across scopes, checked
 * server-side (the same checks the service layer performs).
 */
class AgentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPlatformRole(?string $role): User
    {
        return User::create([
            'name' => 'U-'.Str::random(6),
            'email' => Str::random(10).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => $role,
        ]);
    }

    public function test_platform_owner_holds_every_agent_capability(): void
    {
        $owner = $this->userWithPlatformRole(Roles::PLATFORM_OWNER);
        $access = Access::for($owner);

        foreach (['agents.view', 'agents.run', 'agents.configure', 'agents.cancel', 'agents.approve', 'agents.apply', 'agents.audit'] as $capability) {
            $this->assertTrue($access->allows($capability), "platform_owner lacks {$capability}");
        }
    }

    public function test_platform_admin_holds_configure_but_not_platform_destruction(): void
    {
        $admin = $this->userWithPlatformRole(Roles::PLATFORM_ADMIN);
        $access = Access::for($admin);

        $this->assertTrue($access->allows(Capability::AGENTS_CONFIGURE));
        $this->assertTrue($access->allows(Capability::AGENTS_RUN));
        $this->assertTrue($access->allows(Capability::AGENTS_APPROVE));
    }

    public function test_unauthenticated_users_hold_nothing(): void
    {
        $access = Access::for(null);

        $this->assertFalse($access->allows(Capability::AGENTS_VIEW));
        $this->assertFalse($access->allows(Capability::AGENTS_CONFIGURE));
    }

    public function test_roles_map_is_well_formed_with_the_new_capabilities(): void
    {
        Roles::assertWellFormed(); // throws on unknown capability strings

        $this->assertTrue(Capability::exists('agents.view'));
        $this->assertTrue(Capability::exists('agents.apply'));
    }

    public function test_workspace_and_project_roles_carry_the_documented_agent_boundaries(): void
    {
        $map = Roles::map();

        // Workspace owner/admin: full agent surface except platform config.
        foreach ([Roles::WORKSPACE_OWNER, Roles::WORKSPACE_ADMIN] as $role) {
            $caps = $map['workspace'][$role];
            $this->assertContains(Capability::AGENTS_RUN, $caps);
            $this->assertContains(Capability::AGENTS_APPROVE, $caps);
            $this->assertContains(Capability::AGENTS_APPLY, $caps);
            $this->assertNotContains(Capability::AGENTS_CONFIGURE, $caps, "{$role} must not configure platform runtimes.");
        }

        // Developer: may run, may NOT approve or apply.
        $dev = $map['project'][Roles::DEVELOPER];
        $this->assertContains(Capability::AGENTS_RUN, $dev);
        $this->assertNotContains(Capability::AGENTS_APPROVE, $dev);
        $this->assertNotContains(Capability::AGENTS_APPLY, $dev);
        $this->assertNotContains(Capability::AGENTS_CONFIGURE, $dev);

        // Viewer: visibility only.
        $viewer = $map['project'][Roles::VIEWER];
        $this->assertContains(Capability::AGENTS_VIEW, $viewer);
        $this->assertNotContains(Capability::AGENTS_RUN, $viewer);
    }
}
