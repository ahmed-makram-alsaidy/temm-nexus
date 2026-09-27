<?php

namespace Tests\Feature\Phase27;

use App\Models\MigrationAnalysis;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorLifecycleService;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\SecretVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27F — connector lifecycle: configure → test → probe → analyze →
 * disable → remove (history preserved, secret refs revoked).
 */
class ConnectorLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
    }

    protected function makeConfiguredSource(string $dataset): MigrationSource
    {
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');
        $source = $connector->createSourceProfile($this->projectA, [], ['dataset_path' => $dataset, 'display_name' => 'Lifecycle DS']);
        $this->actingAs($this->admin);
        $source->update(['connector_key' => 'example-json', 'connector_version' => $connector->manifest()->version()]);

        return $source;
    }

    public function test_connector_version_is_stamped_on_sources_and_analyses(): void
    {
        // 27F.2/27I.2 — versions recorded for reproducibility.
        $dataset = $this->buildJsonDataset();
        $source = $this->makeConfiguredSource($dataset);
        $this->assertSame('example-json', $source->effectiveConnectorKey());
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $source->connector_version);

        $service = new \App\Services\ControlPlane\Migration\MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $this->assertSame('example-json', $analysis->connector_key);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $analysis->connector_version);
        $this->assertSame(\App\Services\ControlPlane\Migration\MigrationCenterService::ANALYSIS_VERSION, $analysis->analysis_version);
        $this->assertNotNull($analysis->source_fingerprint);

        // Artifact traceability (27I.2).
        $artifact = \App\Models\MigrationArtifact::where('migration_analysis_id', $analysis->id)->first();
        $this->assertNotNull($artifact);
        $this->assertSame('example-json', $artifact->summary['connector_key']);
        $this->assertSame($analysis->source_fingerprint, $artifact->summary['fingerprint']);
    }

    public function test_disable_stops_invocation_but_keeps_configuration(): void
    {
        $source = $this->makeConfiguredSource($this->buildJsonDataset());
        ConnectorLifecycleService::disable($source);
        $this->assertSame('disabled', $source->fresh()->status);
        $this->assertSame(ConnectorCapabilityProbe::health($source), 'DISABLED');
        // configuration is intact — the operator can re-enable
        $this->assertNotEmpty($source->fresh()->connection);
    }

    public function test_remove_instance_preserves_history_and_revokes_secrets(): void
    {
        // 27F.1 — removing a connector instance must keep migration history.
        $dataset = $this->buildJsonDataset();
        $source = $this->makeConfiguredSource($dataset);
        SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', 'remove-me-password', ['category' => 'database']);
        $source->update(['secret_refs' => ['password' => 'SOURCE_DB_PASSWORD']]);

        $service = new \App\Services\ControlPlane\Migration\MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status);

        ConnectorLifecycleService::removeInstance($source);
        $source->refresh();

        // Configuration + secret references revoked.
        $this->assertSame('disabled', $source->status);
        $this->assertSame([], $source->secret_refs);
        $this->assertNull(\App\Models\ProjectSecret::query()->where('project_id', $this->projectA->id)->where('name', 'SOURCE_DB_PASSWORD')->first(), 'vault secret revoked');

        // History preserved — the analysis row and artifact survive.
        $this->assertDatabaseHas('migration_analyses', ['id' => $analysis->id, 'status' => 'completed']);
        $this->assertDatabaseHas('migration_artifacts', ['migration_analysis_id' => $analysis->id]);
    }

    public function test_remove_keeps_secrets_still_referenced_by_other_sources(): void
    {
        $dataset = $this->buildJsonDataset();
        SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', 'shared-password', ['category' => 'database']);
        $source1 = $this->makeConfiguredSource($dataset);
        $source2 = $this->makeConfiguredSource($dataset);
        foreach ([$source1, $source2] as $source) {
            $source->update(['secret_refs' => ['password' => 'SOURCE_DB_PASSWORD']]);
        }

        ConnectorLifecycleService::removeInstance($source1);
        $this->assertNotNull(\App\Models\ProjectSecret::query()->where('project_id', $this->projectA->id)->where('name', 'SOURCE_DB_PASSWORD')->first(), 'secret still referenced by source2');
        ConnectorLifecycleService::removeInstance($source2);
        $this->assertNull(\App\Models\ProjectSecret::query()->where('project_id', $this->projectA->id)->where('name', 'SOURCE_DB_PASSWORD')->first());
    }

    public function test_contract_test_battery_passes_for_first_party_connectors(): void
    {
        // 27L — every first-party source connector passes the testing SDK.
        foreach (['supabase', 'example-json'] as $key) {
            $connector = ConnectorRegistry::instance()->sourceConnector($key);
            $results = \App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester::run($connector, [
                'project' => $this->projectA,
                'configuration' => ['dataset_path' => $this->buildJsonDataset(name: 'contract-'.$key)],
            ]);
            $summary = \App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester::summarize($results);
            $this->assertSame('PASS', $summary['status'], $key.': '.$summary['detail'].' — '.json_encode(
                collect($results)->filter(fn ($r) => $r['status'] === 'FAIL')->all()
            ));
        }
    }
}
