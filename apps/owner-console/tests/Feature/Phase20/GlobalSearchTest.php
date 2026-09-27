<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\ProjectSecret;
use App\Models\User;
use App\Services\ControlPlane\FunctionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Searchable Probe', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
        $fn = ProjectFunction::create([
            'project_id' => $this->project->id, 'name' => 'Findable', 'slug' => 'cp20-findable',
            'type' => 'static', 'enabled' => true, 'methods' => ['GET'],
            'auth_mode' => 'public', 'timeout_s' => 10, 'rate_limit_per_min' => 60,
        ]);
        FunctionRunner::deploy($fn, ['status' => 200, 'body' => ['ok' => true]], 'static');
        ProjectSecret::create([
            'project_id' => $this->project->id, 'name' => 'CP20_SEARCHABLE',
            'value' => 'shhh-secret-value', 'description' => 'search probe',
        ]);
    }

    public function test_search_finds_scoped_results_without_leaking_values(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/search?q=cp20');
        $response->assertOk();
        $response->assertSee('cp20-findable', false);
        $response->assertSee('CP20_SEARCHABLE', false);
        // Name indexed, value never rendered.
        $response->assertDontSee('shhh-secret-value');

        $response = $this->actingAs($this->admin)->get('/admin/search?q=Searchable');
        $response->assertOk();
        $response->assertSee('Searchable Probe', false);
    }

    public function test_observer_never_sees_secret_names(): void
    {
        \App\Services\ControlPlane\CpAccess::seedDefaults();
        $observer = User::factory()->create(['is_admin' => false, 'cp_role' => 'observer']);
        $response = $this->actingAs($observer)->get('/admin/search?q=cp20');
        $response->assertOk();
        $response->assertDontSee('CP20_SEARCHABLE');
    }
}
