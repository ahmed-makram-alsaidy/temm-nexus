<?php

namespace Tests\Feature\Phase41;

use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0-rc.6 — fresh-install workspace bootstrap.
 *
 * The Clients & Workspaces page is the ONLY place a workspace (client) can
 * be created, and the New Project wizard requires an existing accessible
 * workspace. Entry therefore must be granted to a user who holds the
 * platform create capability even when ZERO workspaces exist — previously
 * the page 403'd on a fresh install, leaving no UI path to create the
 * first client. Users without the capability stay fail-closed.
 */
class WorkspaceBootstrapAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    protected function plainUser(): User
    {
        return User::create([
            'name' => 'Plain User',
            'email' => uniqid('plain').'@test.local',
            'password' => 'password-password-123',
        ]);
    }

    public function test_owner_reaches_workspaces_page_with_zero_workspaces(): void
    {
        $this->actingAs($this->owner());

        $this->get('/admin/workspaces')->assertOk();
    }

    public function test_plain_user_still_refused_with_zero_workspaces(): void
    {
        $this->actingAs($this->plainUser());

        $this->get('/admin/workspaces')->assertForbidden();
    }

    public function test_owner_sees_the_create_client_action(): void
    {
        $this->actingAs($this->owner());

        $this->get('/admin/workspaces')
            ->assertOk()
            ->assertSee(__('workspaces.title'));
    }
}
