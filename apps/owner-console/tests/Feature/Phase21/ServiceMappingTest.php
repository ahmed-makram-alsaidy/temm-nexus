<?php

namespace Tests\Feature\Phase21;

use App\Models\Project;
use App\Models\ProjectServiceNode;
use App\Models\User;
use App\Services\ControlPlane\InfrastructureHealthService;
use App\Services\ControlPlane\InfrastructureMapper;
use App\Services\ControlPlane\ProjectConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21B: project↔service mapping, endpoint override chain, per-facet
 * project health. No production moves; overrides are data-only here.
 */
class ServiceMappingTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['is_admin' => true]);
        $this->artisan('infra:seed-local');
        $this->project = Project::create([
            'name' => 'Probe A', 'slug' => 'gate-a', 'status' => 'active',
            'db_name' => 'gate_a_db', 'redis_prefix' => 'gatea',
        ]);
    }

    public function test_single_node_default_mapping(): void
    {
        $map = InfrastructureMapper::mapping($this->project);
        $keys = array_keys($map);
        sort($keys);
        $expected = ProjectServiceNode::SERVICES;
        sort($expected);
        $this->assertSame($expected, $keys);
        foreach ($map as $service => $row) {
            $this->assertSame('node-local-01', $row['node'], "service {$service}");
        }
    }

    public function test_endpoint_override_chain(): void
    {
        // Default: environment values.
        $db = InfrastructureMapper::dbEndpoint($this->project);
        $this->assertSame('postgres', $db['host']);

        // Project columns win over env…
        $this->project->forceFill(['db_host' => 'db-remote-sim', 'db_port' => 5433])->save();
        $db = InfrastructureMapper::dbEndpoint($this->project->fresh());
        $this->assertSame(['host' => 'db-remote-sim', 'port' => 5433], $db);

        // …and an explicit mapping override wins over columns.
        ProjectServiceNode::updateOrCreate(
            ['project_id' => $this->project->id, 'service' => 'db'],
            ['endpoint_override' => 'db-01:5432']
        );
        $db = InfrastructureMapper::dbEndpoint($this->project->fresh());
        $this->assertSame(['host' => 'db-01', 'port' => 5432], $db);
    }

    public function test_connection_re_resolves_after_endpoint_change(): void
    {
        $conn = ProjectConnectionManager::connection($this->project);
        $this->assertTrue(ProjectConnectionManager::ping($this->project));

        // Point at a dead endpoint: ping fails fast (connection refused on a
        // closed loopback port — proves the override is live, not a cached
        // localhost), then restore and confirm recovery.
        $this->project->forceFill(['db_host' => '127.0.0.1', 'db_port' => 5499])->save();
        $this->assertFalse(ProjectConnectionManager::ping($this->project->fresh()));

        $this->project->forceFill(['db_host' => null, 'db_port' => null])->save();
        $this->assertTrue(ProjectConnectionManager::ping($this->project->fresh()));
        $this->assertSame($conn, ProjectConnectionManager::connection($this->project->fresh()));
    }

    public function test_project_facets_not_single_blob(): void
    {
        $health = InfrastructureHealthService::forProject($this->project);
        foreach (['api', 'database', 'redis', 'workers', 'realtime'] as $facet) {
            $this->assertArrayHasKey($facet, $health['facets']);
            $this->assertArrayHasKey('status', $health['facets'][$facet]);
            $this->assertArrayHasKey('node', $health['facets'][$facet]);
        }
        $this->assertSame('healthy', $health['facets']['database']['status']);
        $this->assertSame('single', $health['profile']);
    }
}
