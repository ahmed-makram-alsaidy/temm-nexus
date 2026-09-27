<?php

namespace Tests\Feature\Phase26;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 26N — first-run polish: clean empty dashboard, generic version, no private names. */
class EmptyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_dashboard_shows_both_primary_actions_and_no_demo_data(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        \App\Services\ControlPlane\CpAccess::seedDefaults();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('No projects yet', false);
        $response->assertSee('Create new project', false);
        $response->assertSee('Import existing project', false);
        $response->assertSee('v'.config('platform.version'), false);
        // A fresh instance must not display private/customer artifacts.
        foreach (['sample-project', 'Template API (gate proof)', 'demo customer'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
    }
}
