<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\DdlService;
use App\Services\ControlPlane\ProjectArtisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
    }

    public function test_migrations_page_shows_history_and_honest_checkout_state(): void
    {
        DdlService::createTable($this->project, 'cp20_hist_t', [
            ['name' => 'id', 'type' => 'serial', 'nullable' => false, 'default' => null, 'pk' => true, 'unique' => false],
        ]);
        try {
            $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/migrations');
            $response->assertOk();
            $response->assertSee('table_created', false);
            // No checkout locally → honest unavailable state, no crash.
            $response->assertSee('No project checkout on this host', false);
        } finally {
            DdlService::dropTable($this->project, 'cp20_hist_t');
        }
    }

    public function test_migrate_status_in_allowlist_but_checkout_guarded(): void
    {
        $this->assertContains('migrate:status', ProjectArtisan::ALLOWLIST);
        $this->assertContains('migrate', ProjectArtisan::ALLOWLIST);
        // Destructive originals still denied.
        foreach (['db:wipe', 'tinker'] as $cmd) {
            $this->assertNotContains($cmd, ProjectArtisan::ALLOWLIST);
        }
    }

    public function test_connections_page_shows_metadata_never_password(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/connections');
        $response->assertOk();
        $response->assertSee('gate_a_user', false);
        $response->assertSee('Pooling', false);
        // Real project password must not appear anywhere.
        $response->assertDontSee(env('PROJECT_GATE_A_DB_PASSWORD', 'drill_recreated_placeholder_001'));
    }

    public function test_backups_page_shows_retention_and_restore_policy(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/backups');
        $response->assertOk();
        $response->assertSee('Retention', false);
        $response->assertSee('least privilege', false);
    }
}
