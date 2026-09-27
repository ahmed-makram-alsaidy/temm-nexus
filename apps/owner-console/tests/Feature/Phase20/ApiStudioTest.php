<?php

namespace Tests\Feature\Phase20;

use App\Models\ApiRouteSnapshot;
use App\Models\Project;
use App\Models\ProjectFunction;
use App\Models\User;
use App\Services\ControlPlane\ApiStudioService;
use App\Services\ControlPlane\FunctionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiStudioTest extends TestCase
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
            'api_domain' => 'api.gate-a.test',
        ]);
    }

    public function test_page_renders_and_snapshot_lists_routes(): void
    {
        // Local checkouts carry storage only (no artisan), so the snapshot is
        // seeded the way a checkout refresh would store it; the refresh path
        // itself is covered by its clean 422 when no checkout exists.
        \App\Models\ApiRouteSnapshot::create([
            'project_id' => $this->project->id,
            'routes' => [
                ['method' => 'GET', 'uri' => '/api/health', 'name' => 'api.health', 'middleware' => [], 'auth' => 'public'],
                ['method' => 'POST', 'uri' => '/api/orders', 'name' => 'api.orders.store', 'middleware' => ['auth:sanctum'], 'auth' => 'auth'],
            ],
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->project->id.'/api');
        $response->assertOk();
        $response->assertSee('/api/orders', false);

        try {
            ApiStudioService::refreshSnapshot($this->project);
            $this->fail('Expected 422 without a project checkout.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_ssrf_guard_blocks_private_targets(): void
    {
        foreach ([
            'http://169.254.169.254/latest/meta-data/',
            'http://localhost/f/x/y',
            'http://127.0.0.1/f/x/y',
            'http://10.0.0.5/secret',
            'https://evil.example.com/hook',
            'ftp://console.test/x',
        ] as $url) {
            try {
                ApiStudioService::send($this->project, 'GET', $url);
                $this->fail("Expected refusal for {$url}");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 422], $url);
            }
        }
    }

    public function test_tester_transport_reaches_app_through_proxy(): void
    {
        // Fixture-free proof of the full tester path (allowlist → Host routing
        // → Caddy → FPM → Laravel → HTTP response). Function-specific 200s are
        // proven at runner/controller level and on the real DB in E2E.
        $result = ApiStudioService::send(
            $this->project, 'GET', 'https://console.test/admin/login'
        );
        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('Sign in', $result['body']);
    }

    public function test_openapi_download(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/project-openapi/'.$this->project->id)
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.3');
    }
}
