<?php

namespace Tests\Feature\Phase22;

use App\Models\Project;
use App\Models\ProjectApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22.1 live-gate regression for the project Connect page.
 * Renders against sqlite :memory: (RefreshDatabase) — no demo database needed.
 */
class ConnectPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->project = Project::create([
            'name' => 'Gate Project', 'slug' => 'gate-project', 'status' => 'active',
            'api_domain' => 'api.gate-project.test', 'api_version' => 'v1',
        ]);
        ProjectApiKey::create([
            'project_id' => $this->project->id,
            'name' => 'gate key',
            'prefix' => 'cp_gateprefix',
            'key_hash' => hash('sha256', 'cp_gateprefix.testsecretvalue0000000000000000'),
            'scopes' => ['functions:invoke'],
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/projects/'.$this->project->id.'/connect')
            ->assertRedirect('/admin/login');
    }

    public function test_connect_renders_with_correct_endpoint_and_tabs(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/connect');

        $response->assertOk();
        $response->assertSee('api.gate-project.test');
        $response->assertSee('cp_gateprefix');
        foreach (['JavaScript', 'React', 'Flutter', 'PHP', 'cURL'] as $tab) {
            $response->assertSee($tab);
        }
        $response->assertSee('/f/gate-project/');
    }

    public function test_connect_never_renders_secret_material(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/connect');

        $response->assertOk();
        $response->assertDontSee('testsecretvalue0000000000000000');
        $response->assertDontSee('key_hash');
    }
}
