<?php

namespace Tests\Feature\Phase21;

use App\Models\InfrastructureEvent;
use App\Models\InfrastructureNode;
use App\Models\InfrastructureService;
use App\Models\User;
use App\Services\ControlPlane\NodeHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21B: heartbeat ingest, token auth, stale (degraded/offline)
 * transitions. Local-only; no remote hosts involved.
 */
class NodeHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['is_admin' => true]);
        $this->artisan('infra:seed-local');
    }

    public function test_heartbeat_ingest_reports_services(): void
    {
        $node = InfrastructureNode::where('name', 'node-local-02')->firstOrFail();
        $result = NodeHeartbeatService::ingest($node, [
            'cpu' => 12.5, 'ram' => 33.0, 'disk' => 55.5,
            'agent_version' => 'local-sim-21.0',
            'services' => [
                ['key' => 'postgres', 'status' => 'healthy', 'version' => '17'],
                ['key' => 'nope', 'status' => 'healthy'],
            ],
        ]);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame(1, $result['services']); // unknown key ignored
        $this->assertSame('healthy', $node->fresh()->computedStatus());
        $this->assertTrue(InfrastructureService::query()
            ->where('node_id', $node->id)->where('key', 'postgres')->exists());
    }

    public function test_stale_nodes_degrade_then_go_offline(): void
    {
        $node = InfrastructureNode::where('name', 'node-local-02')->firstOrFail();

        $node->forceFill(['last_seen_at' => now()->subMinutes(2), 'status' => 'healthy'])->save();
        $this->assertSame('degraded', $node->fresh()->computedStatus());

        $node->forceFill(['last_seen_at' => now()->subMinutes(10), 'status' => 'healthy'])->save();
        $this->assertSame('offline', $node->fresh()->computedStatus());

        $t = NodeHeartbeatService::markStale();
        $this->assertSame('offline', $node->fresh()->status);
        $this->assertGreaterThanOrEqual(1, $t['offline'] + $t['degraded']);
        $this->assertTrue(InfrastructureEvent::query()
            ->where('source', 'node-heartbeat')->where('message', 'like', '%node-local-02%')->exists());
    }

    public function test_disabled_node_reports_offline(): void
    {
        $node = InfrastructureNode::where('name', 'node-local-02')->firstOrFail();
        $node->forceFill(['enabled' => false])->save();
        $this->assertSame('offline', $node->fresh()->computedStatus());
    }

    public function test_agent_token_auth_flow(): void
    {
        $node = InfrastructureNode::where('name', 'node-local-02')->firstOrFail();
        $plain = $node->rotateToken();
        $this->assertNotSame($plain, $node->fresh()->token_hash);
        $this->assertStringStartsWith('nd_', $plain);

        // Valid token pushes a heartbeat…
        $this->postJson('/cp-nodes/heartbeat', ['cpu' => 9], ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->assertJsonPath('result.node', 'node-local-02');

        // …bogus token is refused, and nothing executes on any host.
        $this->postJson('/cp-nodes/heartbeat', [], ['Authorization' => 'Bearer nd_deadbeef'])
            ->assertUnauthorized();
        $this->postJson('/cp-nodes/heartbeat', [])->assertUnauthorized();

        // Rotation revokes the previous token.
        $node->rotateToken();
        $this->postJson('/cp-nodes/heartbeat', [], ['Authorization' => 'Bearer '.$plain])
            ->assertUnauthorized();
    }

    public function test_seed_is_idempotent_and_single_node_default(): void
    {
        $this->artisan('infra:seed-local');
        $this->assertSame(1, InfrastructureNode::where('name', 'node-local-01')->count());
        $local = InfrastructureNode::where('name', 'node-local-01')->firstOrFail();
        $this->assertContains('database', $local->roles);
        $this->assertSame(
            count(InfrastructureService::KEYS),
            InfrastructureService::where('node_id', $local->id)->whereNull('project_id')->count()
        );
    }
}
