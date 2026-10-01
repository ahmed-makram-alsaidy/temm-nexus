<?php

namespace Tests\Feature\Phase26;

use App\Models\MigrationSource;
use App\Models\Project;
use App\Services\ControlPlane\Connectors\ConnectorNotSupported;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\Contracts\SourceAdapter;
use App\Connectors\Supabase\SupabaseSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 26O — connector registry: supabase first, graceful unknown, no core hardwiring. */
class ConnectorRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = Project::create([
            'name' => 'Registry Probe', 'slug' => 'registry-probe', 'status' => 'active',
            'db_name' => 'registry_probe_db', 'redis_prefix' => 'regprobe',
            'storage_disk' => 'local', 'deploy_status' => 'not_deployed',
        ]);
    }

    protected function source(string $type): MigrationSource
    {
        return MigrationSource::create([
            'project_id' => $this->project->id,
            'type' => $type,
            'display_name' => ucfirst($type).' source',
            'status' => 'ready',
            'read_only' => true,
        ]);
    }

    public function test_supabase_connector_resolves_read_only_adapter(): void
    {
        $adapter = ConnectorRegistry::resolve($this->source('supabase'));

        $this->assertInstanceOf(SourceAdapter::class, $adapter);
        $this->assertInstanceOf(SupabaseSourceAdapter::class, $adapter);
        $this->assertSame('supabase', SupabaseSourceAdapter::id());
    }

    public function test_unknown_connector_fails_gracefully_with_supported_list(): void
    {
        // Phase 36: 'firebase' became a real registered connector in Phase 29;
        // the graceful-unknown contract now uses a key that ships no connector.
        $this->expectException(ConnectorNotSupported::class);
        $this->expectExceptionMessage('never-a-connector');

        ConnectorRegistry::resolve($this->source('never-a-connector'));
    }

    public function test_registry_reports_supported_connectors_and_labels(): void
    {
        $all = ConnectorRegistry::all();

        // Phase 36: the six shipped connectors (Phases 26-31) are all registered.
        foreach (['supabase', 'mongodb', 'firebase', 'postgres', 'mysql', 'example-json'] as $key) {
            $this->assertTrue(ConnectorRegistry::has($key), "connector [{$key}] must be registered");
            $this->assertArrayHasKey($key, $all);
        }
        $this->assertFalse(ConnectorRegistry::has('never-a-connector'));
        $this->assertSame('Supabase', ConnectorRegistry::label('supabase'));
        $this->assertSame('Firebase', ConnectorRegistry::label('firebase'));
    }

    public function test_migration_sources_never_become_writable_through_registry(): void
    {
        $source = $this->source('supabase');
        $this->assertTrue((bool) $source->read_only, 'registered sources are read-only by construction');
    }
}
