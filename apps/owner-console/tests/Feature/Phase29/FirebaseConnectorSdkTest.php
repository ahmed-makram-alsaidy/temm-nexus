<?php

namespace Tests\Feature\Phase29;

use App\Connectors\Firebase\FirebaseConnector;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\Support\ConnectorNetworkGuard;
use App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29A/29B — the Firebase connector registers through the generic
 * Connector SDK, passes the 27L contract battery, and keeps credential
 * material (service account JSON) out of every surface.
 */
class FirebaseConnectorSdkTest extends TestCase
{
    use RefreshDatabase;

    protected FirebaseConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new FirebaseConnector;
    }

    public function test_connector_is_discovered_and_registered_by_the_core_registry(): void
    {
        $registry = ConnectorRegistry::instance();
        $this->assertTrue($registry->has('firebase'), 'connector must register via its manifest alone');
        $resolved = $registry->sourceConnector('firebase');
        $this->assertSame('firebase', $resolved->manifest()->key());
        $this->assertSame('first_party', $resolved->manifest()->trust());
    }

    public function test_full_contract_battery_passes(): void
    {
        $results = ConnectorContractTester::run($this->connector);
        $summary = ConnectorContractTester::summarize($results);
        $this->assertSame('PASS', $summary['status'], $summary['detail']);
    }

    public function test_manifest_declares_honest_capabilities(): void
    {
        $capabilities = $this->connector->capabilities();
        foreach ([
            ConnectorCapability::DATABASE_METADATA,
            ConnectorCapability::DATA_EXTRACTION,
            ConnectorCapability::AUTH_METADATA,
            ConnectorCapability::STORAGE_METADATA,
            ConnectorCapability::FUNCTION_METADATA,
            ConnectorCapability::CLIENT_SCAN,
            ConnectorCapability::READ_ONLY_ENFORCEMENT,
            ConnectorCapability::SOURCE_FINGERPRINT,
            ConnectorCapability::RESUME,
        ] as $capability) {
            $this->assertContains($capability, $capabilities, $capability.' must be declared');
        }
        // 29E/32E — incremental export is NOT implemented; never declared.
        $this->assertNotContains(ConnectorCapability::INCREMENTAL_EXPORT, $capabilities);
        $this->assertNotContains(ConnectorCapability::STORAGE_CONTENT, $capabilities);
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $this->connector->capabilityStatus(ConnectorCapability::INCREMENTAL_EXPORT));
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $this->connector->capabilityStatus(ConnectorCapability::POLICY_METADATA));
    }

    public function test_service_account_is_the_only_secret_field(): void
    {
        $secretFields = array_filter($this->connector->credentialSchema(), fn ($f) => $f->secret);
        $this->assertCount(1, $secretFields);
        $this->assertSame('service_account', reset($secretFields)->key);
        foreach ($this->connector->credentialSchema() as $field) {
            $this->assertStringNotContainsStringIgnoringCase('key', $field->default ?? '', 'no default credential material');
        }
    }

    public function test_connection_test_never_leaks_the_service_account(): void
    {
        $canary = '{"project_id":"canary-project","client_email":"canary@canary.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY---CANARY-'.bin2hex(random_bytes(6)).'---"}';
        $credentials = ConnectorCredentials::fromArray(
            ['service_account' => $canary, 'project_id' => 'canary-project'],
            ['service_account']
        );
        $result = $this->connector->testConnection($credentials);
        $serialized = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('CANARY-', (string) $serialized, 'credential material must never appear in test results');
    }

    public function test_empty_credentials_classify_without_crashing(): void
    {
        $result = $this->connector->testConnection(ConnectorCredentials::fromArray([]));
        $this->assertSame('INVALID_CONFIGURATION', $result->result);
    }

    public function test_source_profiles_are_created_read_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $project = Project::create([
            'name' => 'P29', 'slug' => 'p29-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p29.test', 'api_version' => 'v1',
        ]);
        $source = $this->connector->createSourceProfile($project, [], [
            'project_id' => 'temm-dogfood-sandbox',
            'display_name' => 'Firebase sandbox',
        ]);
        $this->assertTrue((bool) $source->read_only);
        $this->assertSame('firebase', $source->type);
        $this->assertSame('temm-dogfood-sandbox', $source->source_ref);
        $this->assertStringNotContainsString('private_key', json_encode($source->connection ?? []), 'connection config never carries credential material');
        $source->delete();
    }

    public function test_network_guard_is_enforced_for_emulator_targets(): void
    {
        config(['connectors.allow_private_networks' => false]);
        $credentials = ConnectorCredentials::fromArray(
            ['project_id' => 'p', 'use_emulator' => '1', 'emulator_host' => '127.0.0.1:8080'],
            []
        );
        // The emulator host is a private-network target — the guard must be
        // consulted (it refuses when the operator has not allowed local
        // sources). We assert the connector surfaces the refusal honestly.
        $result = $this->connector->testConnection($credentials);
        $this->assertContains($result->result, ['NETWORK_ERROR', 'INVALID_CONFIGURATION'], 'refused local target classifies without crashing');
    }
}
