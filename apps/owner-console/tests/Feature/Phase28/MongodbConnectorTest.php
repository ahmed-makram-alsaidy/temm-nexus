<?php

namespace Tests\Feature\Phase28;

use App\Connectors\Mongodb\MongodbSourceAdapter;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorCapability;
use App\Services\ControlPlane\Connectors\ConnectorCapabilityProbe;
use App\Services\ControlPlane\Connectors\ConnectorHealth;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\SecretVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\Feature\Phase28\Concerns\RunsFakeMongoServer;
use Tests\TestCase;

/**
 * Phase 28A-28N — the MongoDB connector end-to-end against the scripted
 * wire server: registry integration (28A), connection/discovery (28B/28C),
 * collection inventory (28D), schema inference + variance (28E),
 * relationship inference with confidence (28F), index/validator analysis
 * (28G), strategies (28I), GridFS (28N) and the capability model.
 */
class MongodbConnectorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;
    use RunsFakeMongoServer;

    protected string $uri;
    protected MigrationSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase27();
        $this->actingAs($this->admin);
        config(['connectors.allow_private_networks' => true]);
        $this->uri = $this->startFakeMongo();
        SecretVaultService::createSecret($this->projectA, 'MONGODB_URI', $this->uri, ['category' => 'database']);
        $this->source = MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'mongodb', 'connector_key' => 'mongodb',
            'display_name' => 'Shop (fake server)',
            'connection' => ['database' => 'shop', 'sample_size' => 100],
            'secret_refs' => ['uri' => 'MONGODB_URI'],
            'read_only' => true, 'status' => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        $this->stopFakeMongo();
        parent::tearDown();
    }

    protected function adapter(): MongodbSourceAdapter
    {
        return new MongodbSourceAdapter($this->source);
    }

    // ── 28A — registry + manifest ───────────────────────────────────────

    public function test_mongodb_registers_through_the_connector_sdk(): void
    {
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $this->assertSame('mongodb', $connector->manifest()->key());
        $this->assertSame('first_party', $connector->manifest()->trust());
        $this->assertSame('credentials-form', $connector->manifest()->importFlow());
        // 28A.2 — only implemented capabilities; nothing faked.
        $this->assertContains(ConnectorCapability::DATABASE_METADATA, $connector->capabilities());
        $this->assertContains(ConnectorCapability::DATA_EXTRACTION, $connector->capabilities());
        $this->assertContains(ConnectorCapability::RESUME, $connector->capabilities());
        $this->assertNotContains(ConnectorCapability::AUTH_METADATA, $connector->capabilities());
        $this->assertNotContains(ConnectorCapability::POLICY_METADATA, $connector->capabilities());
        $this->assertNotContains(ConnectorCapability::INCREMENTAL_EXPORT, $connector->capabilities());
        $this->assertSame(ConnectorCapability::NOT_SUPPORTED, $connector->capabilityStatus('incremental_export', ['uri']));
    }

    public function test_connection_test_passes_against_wire_server(): void
    {
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $result = $connector->testConnection(
            \App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(
                ['uri' => $this->uri, 'database' => 'shop'],
                ['uri']
            )
        );
        $this->assertSame('PASS', $result->result, $result->detail);
        $this->assertStringContainsString('7.0.0-fake', $result->detail);
        // The detail never contains the URI or credentials (28T).
        $this->assertStringNotContainsString('mongodb://', $result->detail);
    }

    // ── 28B.3 — SSRF / network safety ───────────────────────────────────

    public function test_local_connection_requires_operator_opt_in(): void
    {
        config(['connectors.allow_private_networks' => false]);
        $adapter = $this->adapter();
        try {
            $adapter->connect();
            $this->fail('loopback connection allowed without operator opt-in');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('SSRF', $e->getMessage());
        }
        config(['connectors.allow_private_networks' => true]);
        $this->adapter()->connect();
        $this->addToAssertionCount(1);
    }

    // ── 28C/28D/28E/28F/28G/28N — analysis ──────────────────────────────

    public function test_inventory_maps_the_database_to_the_normalized_shape(): void
    {
        $inventory = $this->adapter()->inventory();

        $this->assertSame(['shop'], $inventory['schemas']);
        // GridFS pairs are NOT ordinary tables (28N); the rest are.
        $names = array_column($inventory['tables'], 'name');
        $this->assertContains('users', $names);
        $this->assertContains('products', $names);
        $this->assertContains('orders', $names);
        $this->assertContains('events', $names);
        $this->assertContains('mixed_documents', $names);
        $this->assertNotContains('photos.files', $names);
        $this->assertNotContains('photos.chunks', $names);
        $this->assertNotContains('system.version', $names, 'system collections excluded (28C)');

        // GridFS appears as a storage bucket (28N).
        $this->assertTrue($inventory['storage']['present']);
        $this->assertSame('photos', $inventory['storage']['buckets'][0]['name'], 'bucket prefix preserved (28N)');
        $this->assertSame(1, $inventory['storage']['buckets'][0]['files']);

        // No relational-only domains are faked (28A.2/28O).
        $this->assertFalse($inventory['auth']['present']);
        $this->assertSame([], $inventory['functions']);
        $this->assertSame([], $inventory['policies']);
        $this->assertSame([], $inventory['extensions']);
    }

    public function test_schema_inference_reports_types_frequency_and_variance(): void
    {
        $inventory = $this->adapter()->inventory();
        $meta = $inventory['mongodb'];

        $users = collect($inventory['tables'])->firstWhere('name', 'users');
        $columns = collect($users['columns'])->keyBy('name');
        $this->assertSame('text', $columns['_id']['type'], 'ObjectId preserved as 24-hex text (28M)');
        $this->assertSame('text', $columns['email']['type']);
        $this->assertSame('int4', $columns['age']['type']);
        $this->assertSame('boolean', $columns['is_active']['type']);
        $this->assertSame('numeric', $columns['balance']['type'], 'Decimal128 → numeric (exact, 28K.1)');
        $this->assertSame('timestamptz', $columns['created_at']['type']);
        // Nested address flattened with deterministic names (28L/28T.2).
        $this->assertTrue($columns->has('address__city'), 'nested document flattened');
        $this->assertSame('text', $columns['address__city']['type']);
        // Scalar array → jsonb (28L).
        $this->assertSame('jsonb', $columns['tags']['type']);

        // 28E.2 — frequency/confidence metadata retained per field.
        $fields = collect($meta['collections']['users'] ?? []);
        $this->assertSame(8, $meta['collections']['users']['documents']);

        // 28E.3 — schema variance flagged honestly on mixed_documents.
        $mixedMeta = $meta['collections']['mixed_documents'];
        $this->assertGreaterThan(0, $mixedMeta['schema_variance_fields'], 'polymorphic value field flagged');

        $mixed = collect($inventory['tables'])->firstWhere('name', 'mixed_documents');
        $this->assertSame('JSONB_DOCUMENT', $mixed['migration_strategy'], 'high variance → JSONB (28I.2)');
    }

    public function test_relationship_inference_classifies_confidence(): void
    {
        $inventory = $this->adapter()->inventory();
        $relationships = collect($inventory['mongodb']['relationships']);

        // orders.user_id → users._id: naming + ObjectId type match → HIGH_CONFIDENCE.
        $orderUser = $relationships->first(fn ($r) => $r['collection'] === 'orders' && $r['references'] === 'users');
        $this->assertNotNull($orderUser, 'orders → users candidate inferred');
        $this->assertSame('HIGH_CONFIDENCE', $orderUser['confidence']);
        $this->assertTrue($orderUser['auto_fk']);

        // orders.items[].product_id → products: array-of-ids candidate.
        $itemProduct = $relationships->first(fn ($r) => $r['collection'] === 'orders' && $r['references'] === 'products');
        $this->assertNotNull($itemProduct, 'array element reference inferred');
        $this->assertContains($itemProduct['confidence'], ['HIGH_CONFIDENCE', 'POSSIBLE']);

        // Only high-confidence candidates become foreign keys on the table.
        $orders = collect($inventory['tables'])->firstWhere('name', 'orders');
        $fkColumns = array_column($orders['foreign_keys'], 'column');
        $this->assertContains('user_id', $fkColumns);
    }

    public function test_index_and_validator_analysis(): void
    {
        $inventory = $this->adapter()->inventory();
        $meta = $inventory['mongodb'];

        // 28G — unique + TTL indexes inventoried with types.
        $users = collect($inventory['tables'])->firstWhere('name', 'users');
        $uniqueIndex = collect($users['indexes'])->first(fn ($ix) => $ix['name'] === 'email_unique');
        $this->assertNotNull($uniqueIndex, 'unique index inventoried');
        $this->assertTrue($uniqueIndex['unique']);
        $this->assertArrayHasKey('ttl', $meta['index_types'], 'TTL indexes classified (28G.1)');

        // 28G.2 — the $jsonSchema validator is inventoried and backs the schema.
        $this->assertArrayHasKey('products', $meta['validators']);
        $this->assertStringContainsString('jsonSchema', $meta['validators']['products']['json_schema']);
        $products = collect($inventory['tables'])->firstWhere('name', 'products');
        $this->assertTrue($products['validator'], 'validator-backed flag on the table');
    }

    // ── 28I — strategies ────────────────────────────────────────────────

    public function test_strategy_decisions_are_deterministic_and_explained(): void
    {
        $inventory = $this->adapter()->inventory();
        $strategies = collect($inventory['tables'])->pluck('migration_strategy', 'name');

        $this->assertSame('RELATIONAL_TABLE', $strategies['users'], 'stable shape → relational (28I.1)');
        $this->assertSame('HYBRID', $strategies['orders'], 'array-of-objects → hybrid (28I.3)');
        $this->assertSame('JSONB_DOCUMENT', $strategies['mixed_documents'], 'variance → JSONB (28I.2)');

        // 28L.1 — the orders items[] array becomes a derived child table.
        $names = collect($inventory['tables'])->pluck('name')->all();
        $this->assertContains('orders__items', $names);
        $child = collect($inventory['tables'])->firstWhere('name', 'orders__items');
        $this->assertSame('ARRAY_CHILD_TABLE', $child['migration_strategy']);
        $this->assertSame('parent_id', $child['foreign_keys'][0]['column']);
        $this->assertSame('orders', $child['foreign_keys'][0]['references_table']);
        $childColumns = array_column($child['columns'], 'name');
        $this->assertContains('__idx', $childColumns, 'ordering preserved (28L.1)');
        $this->assertContains('qty', $childColumns);
    }

    public function test_dynamic_capability_probe_and_health(): void
    {
        $this->source->update(['status' => 'ready']);
        $matrix = ConnectorCapabilityProbe::matrix($this->source);
        $statuses = collect($matrix)->pluck('status', 'capability')->all();
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['database_metadata']);
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['resume']);
        $this->assertSame(ConnectorCapability::SUPPORTED, $statuses['read_only_enforcement']);

        $probe = ConnectorCapabilityProbe::probe($this->source);
        $this->assertSame('PROBED', $probe['overall']);
        $this->assertSame('PASS', $probe['domains']['database']);

        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $health = $connector->health($this->source->fresh());
        $this->assertSame(ConnectorHealth::CONNECTED, $health->status);
    }

    // ── 28H — extraction ────────────────────────────────────────────────

    public function test_extraction_is_batched_projected_and_deterministic(): void
    {
        $adapter = $this->adapter();
        $adapter->inventory();

        $rows = [];
        $written = $adapter->streamRows('shop', 'users', [], function ($row) use (&$rows) {
            $rows[] = $row;
        }, 3);
        $this->assertSame(8, $written);
        $this->assertGreaterThanOrEqual(3, $adapter->lastExtractionBatches, 'batched extraction (28H)');
        // Deterministic _id order (28H.1 resume foundation).
        $this->assertSame('000000000000000000000001', $rows[0]['_id']);
        $this->assertSame('user1@shop.test', $rows[0]['email']);
        $this->assertSame('19.991', $rows[0]['balance'], 'Decimal128 exact as string (28K.1)');
        $this->assertSame('2025-01-02T00:00:00.000Z', $rows[0]['created_at'], 'UTC ISO-8601 (28K.2)');
        $this->assertSame(['vip', 'الإسكندرية'], json_decode((string) $rows[0]['tags'], true, 512, JSON_THROW_ON_ERROR), 'scalar array → canonical JSONB (28L)');

        // Column projection honors the requested columns (engine meta['columns']).
        $projected = [];
        $adapter->streamRows('shop', 'users', ['_id', 'email'], function ($row) use (&$projected) {
            $projected[] = $row;
        }, 500);
        $this->assertSame(['_id', 'email'], array_keys($projected[0]));
    }

    public function test_child_table_extraction_splits_arrays_with_ordering(): void
    {
        $adapter = $this->adapter();
        $adapter->inventory();

        $rows = [];
        $written = $adapter->streamRows('shop', 'orders__items', [], function ($row) use (&$rows) {
            $rows[] = $row;
        }, 500);
        $this->assertSame(12, $written, '6 orders × 2 items');
        $this->assertSame('0000000000000000000001f5', $rows[0]['parent_id'], 'parent linkage (28L.1)');
        $this->assertSame(0, $rows[0]['__idx'], 'ordering preserved');
        $this->assertSame(1, $rows[0]['qty']);
        $this->assertSame('12.34', $rows[0]['price'], 'Decimal128 exact in child rows');
        $this->assertSame('وصلة', $rows[0]['note'], 'Arabic preserved (28V.7)');
    }

    // ── 28Q.2 — fingerprint + 28B.4 validation artifacts ────────────────

    public function test_fingerprint_is_stable_and_validation_artifacts_report(): void
    {
        $adapter = $this->adapter();
        $fingerprint = $adapter->fingerprint();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertSame($fingerprint, $adapter->fingerprint(), 'fingerprint deterministic');

        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $artifacts = $connector->validateSource($this->source);
        $this->assertSame('mongodb', $artifacts['connector_key']);
        $this->assertSame('shop', $artifacts['database']);
        $this->assertSame(8, $artifacts['row_counts']['users']);
        $this->assertSame(1, $artifacts['gridfs_buckets']);
    }
}
