<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\ProjectHealthService;
use App\Services\ControlPlane\ProjectStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Concerns\RequiresDemoDatabase;

class WorkspacePagesTest extends TestCase
{
    use RefreshDatabase;
    use RequiresDemoDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDemoDatabase();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->demo = Project::create([
            'name' => 'Control Plane Demo', 'slug' => 'control-plane-demo', 'status' => 'active',
            'db_name' => 'control_plane_demo_db', 'redis_prefix' => 'control_plane_demo',
            'api_domain' => 'api.control-plane-demo.test',
        ]);
    }

    public function test_overview_shows_own_resources_only(): void
    {
        $health = ProjectHealthService::for($this->demo)->check();
        $this->assertTrue($health['database']['reachable']);
        $this->assertTrue($health['redis']['reachable']);
        $this->assertEquals('control-plane-demo', $health['project']);
        $this->assertNotNull($health['application']['laravel_version']);

        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->demo->id);
        $response->assertOk();
        $response->assertSee('control-plane-demo');
        $response->assertDontSee('gate_b_db');
    }

    public function test_db_health_page_shows_project_metrics(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->demo->id.'/db-health');
        $response->assertOk();
        $response->assertSee('control_plane_demo_db');
    }

    public function test_storage_bucket_lifecycle(): void
    {
        $storage = ProjectStorageManager::for($this->demo);
        try {
            $storage->deleteBucket('invoices');
        } catch (\Throwable) {
        }
        $storage->createBucket('invoices', 'private');
        $this->assertContains('invoices', array_column($storage->buckets(), 'name'));

        $key = $storage->store('invoices', '', 'hello.txt', 'hello control plane');
        $this->assertEquals('hello.txt', $key);
        $file = $storage->read('invoices', 'hello.txt');
        $this->assertEquals('hello control plane', $file['contents']);

        $moved = $storage->move('invoices', 'hello.txt', '2026', 'greeting.txt');
        $this->assertEquals('2026/greeting.txt', $moved);

        $response = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/storage?bucket=invoices');
        $response->assertOk();
        $response->assertSee('2026');

        $nested = $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->demo->id.'/storage?bucket=invoices&prefix=2026');
        $nested->assertOk();
        $nested->assertSee('greeting.txt');

        $storage->delete('invoices', '2026/greeting.txt');
        $storage->deleteBucket('invoices');
        $this->assertNotContains('invoices', array_column($storage->buckets(), 'name'));
    }

    public function test_api_page_lists_project_routes(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/projects/'.$this->demo->id.'/api');
        $response->assertOk();
        $response->assertSee('/api/health');
        $response->assertSee('/api/auth/login');
        $response->assertDontSee('telescope');
    }
}
