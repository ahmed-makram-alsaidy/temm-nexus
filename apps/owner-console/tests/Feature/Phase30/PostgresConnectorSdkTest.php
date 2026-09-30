<?php

namespace Tests\Feature\Phase30;

use App\Connectors\Postgres\PostgresConnector;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\Testing\ConnectorContractTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 30A — the generic PostgreSQL connector registers through the
 * Connector SDK, passes the 27L contract battery, and classifies
 * connection failures honestly (no credential leakage).
 */
class PostgresConnectorSdkTest extends TestCase
{
    use RefreshDatabase;

    protected PostgresConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new PostgresConnector;
    }

    public function test_connector_is_discovered_and_registered_by_the_core_registry(): void
    {
        $registry = ConnectorRegistry::instance();
        $this->assertTrue($registry->has('postgres'), 'connector must register via its manifest alone');
        $this->assertSame('postgres', $registry->sourceConnector('postgres')->manifest()->key());
    }

    public function test_full_contract_battery_passes(): void
    {
        $results = ConnectorContractTester::run($this->connector);
        $summary = ConnectorContractTester::summarize($results);
        $this->assertSame('PASS', $summary['status'], $summary['detail']);
    }

    public function test_capabilities_are_honest(): void
    {
        $capabilities = $this->connector->capabilities();
        foreach ([
            ConnectorCapability::DATABASE_METADATA,
            ConnectorCapability::DATA_EXTRACTION,
            ConnectorCapability::FUNCTION_METADATA,
            ConnectorCapability::POLICY_METADATA,
            ConnectorCapability::READ_ONLY_ENFORCEMENT,
            ConnectorCapability::SOURCE_FINGERPRINT,
            ConnectorCapability::RESUME,
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
        // A plain PostgreSQL server has no provider auth/storage domain (30C scope).
        $this->assertNotContains(ConnectorCapability::AUTH_METADATA, $capabilities);
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $this->connector->capabilityStatus(ConnectorCapability::AUTH_METADATA));
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $this->connector->capabilityStatus(ConnectorCapability::INCREMENTAL_EXPORT));
    }

    public function test_connection_test_never_leaks_the_password(): void
    {
        $canary = 'CANARY-PG-'.bin2hex(random_bytes(6));
        $credentials = ConnectorCredentials::fromArray(
            ['host' => '127.0.0.1', 'port' => '5', 'database' => 'd', 'username' => 'u', 'password' => $canary],
            ['password']
        );
        $result = $this->connector->testConnection($credentials);
        $serialized = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($canary, (string) $serialized);
        // Unreachable port classifies as a network error, not a crash.
        $this->assertContains($result->result, ['NETWORK_ERROR', 'INVALID_CREDENTIAL', 'PROVIDER_ERROR']);
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
            'name' => 'P30', 'slug' => 'p30-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p30.test', 'api_version' => 'v1',
        ]);
        $source = $this->connector->createSourceProfile($project, [], [
            'host' => 'pg.internal', 'port' => '5432', 'database' => 'shop',
            'username' => 'reader', 'display_name' => 'Shop database',
        ]);
        $this->assertTrue((bool) $source->read_only);
        $this->assertSame('postgres', $source->type);
        $this->assertStringNotContainsString('password', json_encode($source->connection ?? []), 'connection config never carries the password');
        $source->delete();
    }
}
