<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerConsoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_on_fresh_platform_is_redirected_to_setup(): void
    {
        // Phase 26D: login is not exposed before bootstrap completes.
        $this->get('/admin')->assertRedirect('/setup');
    }

    public function test_guest_on_initialized_platform_is_redirected_to_login(): void
    {
        User::factory()->create(['is_admin' => true]);
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_view_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_no_secret_columns_exist_on_projects(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('projects');

        foreach (['password', 'secret', 'api_key', 'token'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }
}
