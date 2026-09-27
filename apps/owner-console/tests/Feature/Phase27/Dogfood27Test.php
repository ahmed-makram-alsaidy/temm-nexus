<?php

namespace Tests\Feature\Phase27;

use App\Models\MigrationAnalysis;
use App\Models\MigrationSource;
use App\Models\Project;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27P — dogfood.
 *
 * 27P.2: the full non-Supabase workflow (register → configure → analyze →
 * plan → migrate → validate) proves the core no longer depends on Supabase.
 * 27P.3: connector switch test — two projects, two different connectors, the
 * same Migration Center, no provider-specific breakage.
 * 27P.1: the Supabase dogfood continues to live in Phase25\Dogfood25Test
 * (registry → supabase connector → discovery → capabilities → analysis on the
 * local demo Supabase stack, READ-ONLY).
 */
class Dogfood27Test extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
        $this->actingAs($this->admin);
    }

    /** 27P.2 — full non-Supabase workflow through the generic pipeline. */
    public function test_example_connector_dogfood_register_configure_analyze_plan_migrate_validate(): void
    {
        $registry = ConnectorRegistry::instance();

        // 1. REGISTER (first-party discovery, asserted on the registry)
        $this->assertArrayHasKey('example-json', $registry->all());

        // 2. CONFIGURE — generic credentials-form flow, secrets → vault refs
        $dataset = $this->buildJsonDataset();
        $connector = $registry->sourceConnector('example-json');
        $test = $connector->testConnection(
            \App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(['dataset_path' => $dataset])
        );
        $this->assertSame('PASS', $test->result);
        $source = $connector->createSourceProfile($this->projectA, [], [
            'dataset_path' => $dataset, 'display_name' => 'Demo-like JSON dataset',
        ]);

        // 3. CAPABILITY PROBE (generic, connector-driven)
        $probe = ConnectorCapabilityProbe::probe($source);
        $this->assertSame('PROBED', $probe['overall']);
        $this->assertSame('PASS', $probe['domains']['database']);

        // 4. ANALYZE → 5. PLAN
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);
        $plan = $service->generatePlan($analysis);
        $this->assertSame(3, $plan->items()->where('source_kind', 'table')->count());

        // 6. MIGRATE (rehearsal, disposable sqlite target)
        $targetPath = storage_path('framework/testing/phase27/dogfood/target-'.uniqid().'.sqlite');
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }
        $manager = new MigrationRunManager;
        $run = $manager->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => ['driver' => 'sqlite', 'path' => $targetPath, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'],
            'target_disposable' => true, 'reset' => true, 'target_environment_type' => 'development',
        ]);
        $manager->execute($run);
        $run = $run->fresh();
        $itemErrors = $run->items()->where('status', 'failed')->pluck('error')->all();
        $this->assertSame('completed', $run->status, json_encode($itemErrors));

        // 7. VALIDATE
        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }
        $this->assertSame('example-json', $analysis->connector_key);
        $this->assertFileExists($targetPath);
    }

    /** 27P.3 — connector switch: Supabase project + JSON project, same center. */
    public function test_connector_switch_two_projects_same_migration_center(): void
    {
        // Project Supabase — supabase connector source (configured, no live
        // provider contact required for page rendering).
        $supabaseSource = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'connector_key' => 'supabase',
            'display_name' => 'Supabase snapshot', 'connection' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'postgres'],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
            'read_only' => true, 'status' => 'ready',
        ]);
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', 'not-a-real-password', ['category' => 'database']);
        $supabaseSource->update(['capabilities' => ['database' => 'PARTIAL']]);

        // Project JSON — example connector source, analyzed for real.
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');
        $jsonSource = $connector->createSourceProfile($this->projectB, [], [
            'dataset_path' => $this->buildJsonDataset(), 'display_name' => 'JSON dataset',
        ]);
        $service = new MigrationCenterService;
        $analysis = $service->analyze($jsonSource);
        $this->assertSame('completed', $analysis->status);
        $service->classify($analysis);

        // Same Migration Center UI renders both projects without provider
        // branching; each shows ITS connector's capability matrix.
        foreach ([[$this->projectA, 'supabase'], [$this->projectB, 'example-json']] as [$project, $key]) {
            $url = \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $project]);
            $content = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($key, $content, "project ".$project->slug." renders its connector");
            $this->assertStringContainsString('Connector capabilities', $content);
        }

        // The Supabase project's capability matrix rows come from ITS
        // connector (13 capabilities), the JSON project's from its own (4).
        $supabaseMatrix = ConnectorCapabilityProbe::matrix($supabaseSource);
        $jsonMatrix = ConnectorCapabilityProbe::matrix($jsonSource->fresh());
        $this->assertCount(13, $supabaseMatrix);
        $this->assertCount(4, $jsonMatrix);
    }

    /** 27P.1 companion — registry-driven Supabase dogfood when the local stack is up. */
    public function test_supabase_dogfood_through_registry_when_local_stack_reachable(): void
    {
        $host = env('CP_PG_HOST', 'postgres');
        if (! @fsockopen($host, 5432, $errno, $errstr, 1.5)) {
            $this->markTestSkipped("local demo Supabase stack ({$host}:5432) not reachable — Phase 25 dogfood covers it when up");
        }
        // Registry → supabase connector → adapter → inventory (READ-ONLY).
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'connector_key' => 'supabase',
            'display_name' => 'Demo local stack', 'connection' => [
                'host' => $host, 'port' => (int) env('CP_PG_PORT', 5432),
                'database' => env('CP_PG_DB', 'demo_source_rehearsal'),
                'username' => env('CP_PG_USER', 'postgres'),
            ],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
            'read_only' => true, 'status' => 'pending',
        ]);
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', env('CP_PG_PASSWORD', 'postgres'), ['category' => 'database']);

        $connector = ConnectorRegistry::instance()->sourceConnector('supabase');
        $inventory = $connector->analyze($source);
        $this->assertNotEmpty($inventory['tables'], 'registry-driven analysis inventories the local stack');
        $fingerprint = $connector->fingerprint($source);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
    }
}
