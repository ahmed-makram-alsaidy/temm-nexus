<?php

namespace Tests\Feature\Phase27;

use App\Models\MigrationSource;
use App\Models\ProjectSecret;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorDefinition;
use App\Services\ControlPlane\Connectors\ConnectorManifest;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\Contracts\SourceConnector;
use App\Services\ControlPlane\Connectors\Support\ScopedSecretResolver;
use App\Services\ControlPlane\SecretVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 27A/27B/27C/27E — Connector SDK: contracts, definitions, capability
 * model, credential schema and vault-backed secret resolution.
 */
class ConnectorSdkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
    }

    public function test_registry_ships_two_first_party_source_connectors(): void
    {
        $all = ConnectorRegistry::all();
        $this->assertArrayHasKey('supabase', $all);
        $this->assertArrayHasKey('example-json', $all);
        foreach (['supabase', 'example-json'] as $key) {
            $connector = ConnectorRegistry::instance()->sourceConnector($key);
            $this->assertInstanceOf(SourceConnector::class, $connector);
            $this->assertSame('first_party', $connector->manifest()->trust());
            $this->assertTrue(ConnectorRegistry::has($key));
        }
    }

    public function test_connector_definitions_have_stable_identity(): void
    {
        foreach (['supabase', 'example-json'] as $key) {
            $definition = ConnectorRegistry::instance()->sourceConnector($key)->definition();
            $this->assertInstanceOf(ConnectorDefinition::class, $definition);
            $this->assertSame($key, $definition->key, 'stable public id, not a class name');
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $definition->version);
            $this->assertNotSame('', $definition->name);
            $this->assertNotSame('', $definition->description);
            $this->assertNotSame('', $definition->author);
            $this->assertNotEmpty($definition->capabilities);
        }
    }

    public function test_analysis_contract_returns_normalized_inventory(): void
    {
        $dataset = $this->buildJsonDataset();
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');
        $source = $connector->createSourceProfile($this->projectA, [], ['dataset_path' => $dataset, 'display_name' => 'DS']);
        $inventory = $connector->analyze($source);

        // 27B.2 — normalized shape identical to the Supabase adapter's.
        foreach (['schemas', 'tables', 'views', 'matviews', 'enums', 'functions', 'triggers', 'policies', 'extensions', 'auth', 'storage', 'realtime', 'cron'] as $section) {
            $this->assertArrayHasKey($section, $inventory, "normalized inventory section {$section} present");
        }
        $this->assertSame(['public'], $inventory['schemas']);
        $this->assertCount(3, $inventory['tables']);
        $orders = collect($inventory['tables'])->firstWhere('name', 'orders');
        $this->assertSame(['id'], $orders['primary_key']);
        $this->assertSame('user_id', $orders['foreign_keys'][0]['column']);
        $this->assertSame('users', $orders['foreign_keys'][0]['references_table']);
        $this->assertFalse($orders['rls_enabled']);
    }

    public function test_capability_model_is_generic_and_honest(): void
    {
        // Vocabulary stability (27C).
        $this->assertContains('project_discovery', ConnectorCapability::ALL);
        $this->assertContains('read_only_enforcement', ConnectorCapability::ALL);
        $this->assertContains('incremental_export', ConnectorCapability::ALL);

        $supabase = ConnectorRegistry::instance()->sourceConnector('supabase');
        // 27C.2 — dynamic status depends on credential presence.
        $this->assertSame(ConnectorCapability::SUPPORTED_WITH_CONFIGURATION, $supabase->capabilityStatus('project_discovery', []));
        $this->assertSame(ConnectorCapability::SUPPORTED, $supabase->capabilityStatus('project_discovery', ['pat']));
        $this->assertSame(ConnectorCapability::SUPPORTED_WITH_CONFIGURATION, $supabase->capabilityStatus('database_metadata', ['pat']));
        $this->assertSame(ConnectorCapability::SUPPORTED, $supabase->capabilityStatus('database_metadata', ['pat', 'password', 'host']));
        // undeclared capability is never faked
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $supabase->capabilityStatus('incremental_export', ['pat']));

        $example = ConnectorRegistry::instance()->sourceConnector('example-json');
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $example->capabilityStatus('auth_metadata', ['dataset_path']));
        $this->assertSame(ConnectorCapability::SUPPORTED, $example->capabilityStatus('read_only_enforcement', []));
    }

    public function test_credential_schema_declares_secret_and_capability_metadata(): void
    {
        $supabase = ConnectorRegistry::instance()->sourceConnector('supabase');
        $schema = collect($supabase->credentialSchema())->keyBy(fn ($f) => $f->key);
        $this->assertTrue($schema['pat']->secret, 'PAT is a secret field');
        $this->assertTrue($schema['password']->secret, 'DB password is a secret field');
        $this->assertFalse($schema['host']->secret);
        $this->assertSame(ConnectorCapability::PROJECT_DISCOVERY, $schema['pat']->capability);
        $this->assertSame('account', $schema['pat']->scope, 'PAT is account-scoped');

        $example = ConnectorRegistry::instance()->sourceConnector('example-json');
        $this->assertCount(1, $example->credentialSchema());
        $this->assertSame('dataset_path', $example->credentialSchema()[0]->key);
    }

    public function test_connection_test_classifies_with_fake_management_api(): void
    {
        Http::fake(['api.supabase.com/v1/projects' => Http::response([], 401)]);
        $supabase = ConnectorRegistry::instance()->sourceConnector('supabase');
        $credentials = \App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(['pat' => 'sbp_bad_token'], ['pat']);
        $result = $supabase->testConnection($credentials);
        $this->assertSame('INVALID_CREDENTIAL', $result->result);
    }

    public function test_connection_test_classifies_dataset_problems(): void
    {
        $example = ConnectorRegistry::instance()->sourceConnector('example-json');
        $missing = $example->testConnection(\App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(['dataset_path' => 'Z:/definitely/not/here']));
        $this->assertSame('NOT_FOUND', $missing->result);
        // A dedicated empty directory (the OS temp dir may hold stray .json files).
        $emptyDir = storage_path('framework/testing/phase27/empty-ds-'.uniqid());
        mkdir($emptyDir, 0777, true);
        $empty = $example->testConnection(\App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(['dataset_path' => $emptyDir]));
        $this->assertSame('INVALID_CONFIGURATION', $empty->result);
        @rmdir($emptyDir);
    }

    public function test_secret_minimization_scopes_operation_credentials(): void
    {
        // 27E.3 — discovery must not receive the database password.
        $connection = \App\Models\ExternalAccountConnection::create([
            'provider' => 'supabase', 'display_name' => 'Acc', 'owner_user_id' => $this->admin->id,
            'secret_encrypted' => 'sbp_account_pat_value', 'status' => 'connected',
        ]);
        SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', 'super-secret-password', ['category' => 'database']);
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'supabase', 'connector_key' => 'supabase',
            'external_account_connection_id' => $connection->id,
            'display_name' => 'S', 'connection' => ['host' => '127.0.0.1'],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'],
            'read_only' => true, 'status' => 'ready',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('supabase');
        $resolver = new ScopedSecretResolver;

        $discoveryCredentials = $resolver->resolveScoped($source, $connector->definition(), $connector->discoveryRequires());
        $this->assertArrayHasKey('pat', $discoveryCredentials->raw());
        $this->assertSame('sbp_account_pat_value', $discoveryCredentials->get('pat'));
        $this->assertArrayNotHasKey('password', $discoveryCredentials->raw(), 'discovery must not receive DB password');

        $analysisCredentials = $resolver->resolveForSource($source, $connector->definition());
        $this->assertSame('super-secret-password', $analysisCredentials->get('password'));
        // secrets never leak through debug surfaces
        $this->assertStringNotContainsString('super-secret-password', json_encode($analysisCredentials->redacted()));
        $this->assertStringNotContainsString('super-secret-password', (string) $analysisCredentials);
    }

    public function test_secret_resolvers_scope_cannot_escape_to_arbitrary_vault_names(): void
    {
        // 27T — a connector cannot fish for vault secrets outside its schema.
        SecretVaultService::createSecret($this->projectA, 'WHATSAPP_TOKEN', 'wa-secret-value', ['category' => 'whatsapp']);
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'supabase', 'connector_key' => 'supabase',
            'display_name' => 'S', 'connection' => [],
            'secret_refs' => ['password' => 'WHATSAPP_TOKEN'],
            'read_only' => true, 'status' => 'ready',
        ]);
        $connector = ConnectorRegistry::instance()->sourceConnector('supabase');
        $resolver = new ScopedSecretResolver;
        // 'password' IS a declared field, so its declared ref resolves —
        // but an UNDECLARED field key can never be resolved:
        $credentials = $resolver->resolveScoped($source, $connector->definition(), ['host']);
        $this->assertArrayNotHasKey('password', $credentials->raw());
        $this->assertArrayNotHasKey('whatsapp_token', $credentials->raw());
    }

    public function test_dynamic_capability_probe_reflects_configuration(): void
    {
        $dataset = $this->buildJsonDataset();
        $connector = ConnectorRegistry::instance()->sourceConnector('example-json');
        $source = $connector->createSourceProfile($this->projectA, [], ['dataset_path' => $dataset, 'display_name' => 'DS']);
        $source->update(['status' => 'ready']);

        $matrix = ConnectorCapabilityProbe::matrix($source);
        $statuses = collect($matrix)->pluck('status', 'capability')->all();
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['database_metadata']);
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['data_extraction']);
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['read_only_enforcement']);

        // Live probe derives generic domains from the normalized inventory.
        $probe = ConnectorCapabilityProbe::probe($source);
        $this->assertSame('PROBED', $probe['overall']);
        $this->assertSame('PASS', $probe['domains']['database']);
        $this->assertSame('ready', $source->fresh()->status);
    }

    public function test_manifest_validation_rejects_bad_packages(): void
    {
        // 27J.3 — malformed manifests are refused with clear errors.
        $cases = [
            [['key' => 'BAD KEY'], 'key'],
            [['key' => 'ok', 'version' => 'not-semver'], 'version'],
            [['key' => 'ok', 'version' => '1.0.0', 'schema_version' => 99], 'schema_version'],
            [['key' => 'ok', 'version' => '1.0.0', 'entrypoint' => '/../etc/passwd'], 'entrypoint'],
            [['key' => 'ok', 'version' => '1.0.0', 'capabilities' => ['teleport']], 'capability'],
        ];
        foreach ($cases as [$override, $needle]) {
            try {
                ConnectorManifest::validate(array_merge([
                    'schema_version' => 1, 'key' => 'ok', 'name' => 'Ok', 'version' => '1.0.0',
                    'description' => 'ok', 'author' => 'x', 'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector',
                    'capabilities' => [], 'trust' => 'first_party',
                ], $override));
                $this->fail("manifest with invalid {$needle} was accepted");
            } catch (\App\Services\ControlPlane\Connectors\ConnectorManifestInvalid $e) {
                $this->assertStringContainsStringIgnoringCase($needle === 'capability' ? 'vocabulary' : $needle, strtolower($e->getMessage()));
            }
        }
    }

    public function test_platform_compatibility_guard(): void
    {
        // 27R — requirements compared against the platform core version line.
        $platform = '0.1.0-rc.2';
        $this->assertTrue(ConnectorManifest::satisfiesPlatform('>=0.1.0', $platform));
        $this->assertFalse(ConnectorManifest::satisfiesPlatform('>=0.2.0', $platform));
        $this->assertTrue(ConnectorManifest::satisfiesPlatform('*', $platform));
        try {
            ConnectorManifest::validate([
                'schema_version' => 1, 'key' => 'future-thing', 'name' => 'Future', 'version' => '1.0.0',
                'description' => 'needs a newer platform', 'author' => 'x',
                'entrypoint' => 'App\Connectors\Supabase\SupabaseConnector',
                'capabilities' => [], 'trust' => 'first_party', 'platform_requirement' => '>=99.0.0',
            ]);
            $this->fail('platform-incompatible manifest was accepted');
        } catch (\App\Services\ControlPlane\Connectors\ConnectorManifestInvalid $e) {
            $this->assertStringContainsString('platform', $e->getMessage());
        }
    }

    public function test_secret_refs_stay_vault_backed_and_encrypted(): void
    {
        // 27E.2 — secret values live in the vault (encrypted at rest), sources
        // only carry the NAME.
        $dataset = $this->buildJsonDataset();
        SecretVaultService::createSecret($this->projectA, 'SOURCE_DB_PASSWORD', 'vault-backed-password', ['category' => 'database']);
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'supabase', 'connector_key' => 'supabase',
            'display_name' => 'S', 'connection' => ['host' => '127.0.0.1'],
            'secret_refs' => ['password' => 'SOURCE_DB_PASSWORD'], 'read_only' => true, 'status' => 'ready',
        ]);
        // Encryption at rest — read the RAW column (bypassing the model cast).
        $raw = \Illuminate\Support\Facades\DB::table('project_secrets')
            ->where('project_id', $this->projectA->id)->where('name', 'SOURCE_DB_PASSWORD')->value('value');
        $this->assertStringNotContainsString('vault-backed-password', (string) $raw, 'secret encrypted at rest');
        $this->assertSame(['password' => 'SOURCE_DB_PASSWORD'], $source->secret_refs);
    }
}
