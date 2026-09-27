<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 26D first-run gate: a fresh (uninitialized) platform redirects
     * the root to /setup; an initialized one serves the landing page.
     */
    public function test_the_root_redirects_to_setup_on_a_fresh_platform(): void
    {
        $this->get('/')->assertRedirect('/setup');
    }

    public function test_the_root_serves_the_landing_page_once_initialized(): void
    {
        User::factory()->create(['is_admin' => true]);
        \Illuminate\Support\Facades\Cache::forget('platform.initialized');

        $this->get('/')->assertOk();
    }
}
