<?php

namespace Tests\Feature\Phase20;

use App\Models\Project;
use App\Models\RealtimeEvent;
use App\Models\User;
use App\Services\ControlPlane\RealtimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeStudioTest extends TestCase
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

    public function test_publish_records_feed_with_honest_level(): void
    {
        // gate-a has no Reverb endpoint configured → recorded + reason.
        $result = RealtimeService::publishTest(
            $this->project, 'demo.orders', 'DemoOrderCreated', ['order_id' => 7]
        );
        $this->assertSame('recorded', $result['level']);
        $this->assertTrue(
            RealtimeEvent::query()->where('project_id', $this->project->id)
                ->where('channel', 'demo.orders')->exists()
        );
    }

    public function test_private_channel_requires_credential(): void
    {
        try {
            RealtimeService::publishTest($this->project, 'private-orders', 'Ping', []);
            $this->fail('Expected 401');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        // Bogus key also refused.
        try {
            RealtimeService::publishTest($this->project, 'private-orders', 'Ping', [], 'nope');
            $this->fail('Expected 401');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function test_bad_channel_and_payload_refused(): void
    {
        try {
            RealtimeService::publishTest($this->project, 'evil channel!!', 'Ping', []);
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_probe_fails_gracefully_on_dead_endpoint(): void
    {
        $probe = RealtimeService::subscribeProbe('127.0.0.1', 9, 2);
        $this->assertFalse($probe['ok']);
        $this->assertNotEmpty($probe['detail']);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/projects/'.$this->project->id.'/realtime')
            ->assertOk();
    }
}
