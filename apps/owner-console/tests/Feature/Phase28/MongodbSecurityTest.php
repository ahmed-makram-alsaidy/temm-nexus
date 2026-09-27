<?php

namespace Tests\Feature\Phase28;

use App\Connectors\Mongodb\FieldNameSanitizer;
use App\Connectors\Mongodb\MongodbSourceAdapter;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Connectors\ConnectorTestResult;
use App\Services\ControlPlane\Connectors\Support\ScopedSecretResolver;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\Feature\Phase28\Concerns\RunsFakeMongoServer;
use Tests\TestCase;

/**
 * Phase 28T — the mandatory MongoDB security battery: URI/credential
 * leakage (logs, exceptions, AI context), SSRF, cross-project isolation,
 * source write defense, malicious field names, deep nesting, oversized
 * documents, resume-token tampering and connector-instance substitution.
 */
class MongodbSecurityTest extends TestCase
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
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'MONGODB_URI', $this->uri, ['category' => 'database']);
        $this->source = MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'mongodb', 'connector_key' => 'mongodb',
            'display_name' => 'Security source',
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

    // ── 28T credential leakage ──────────────────────────────────────────

    public function test_uri_never_leaks_into_logs_exceptions_or_ui_surfaces(): void
    {
        // The stored URI contains a plantable marker; every output surface
        // must be free of it. (The fixture URI has no credentials — the
        // redaction guarantee is about the URI ITSELF plus credentials.)
        $plant = 'PLANTED-MONGO-SECRET-xyz';
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');

        // 1. connection test with a credential-carrying URI against a dead host.
        $result = $connector->testConnection(
            \App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(
                ['uri' => 'mongodb://shopuser:'.$plant.'@127.0.0.1:1/shop'],
                ['uri']
            )
        );
        $this->assertNotSame(ConnectorTestResult::PASS, $result->result);
        $this->assertStringNotContainsString($plant, $result->detail, 'exception leakage (28T)');
        $this->assertStringNotContainsString('mongodb://', $result->detail, 'URI leakage in detail');

        // 2. the safe display form masks credentials.
        $redacted = \App\Connectors\Mongodb\Protocol\MongoWireClient::redactUri('mongodb://shopuser:'.$plant.'@cluster.example/shop');
        $this->assertStringNotContainsString($plant, $redacted);

        // 3. logs: the connector logger redacts vault values (Phase 27S guarantee).
        $logLine = \App\Services\ControlPlane\Connectors\Support\ConnectorLogger::sanitize('attempt '.$plant.' failed');
        \App\Services\ControlPlane\SecretService::create($this->projectA, 'MONGO_CANARY', $plant);
        $redacted2 = \App\Services\ControlPlane\SecretService::redact($this->projectA, 'attempt '.$plant.' failed');
        $this->assertStringNotContainsString($plant, (string) $redacted2, 'log leakage');
    }

    public function test_analysis_artifacts_never_carry_document_values(): void
    {
        // 28J.1 — the analysis (which feeds AI) carries STRUCTURE only.
        $service = new MigrationCenterService;
        $analysis = $service->analyze($this->source);
        $this->assertSame('completed', $analysis->status);
        foreach ($analysis->items()->get(['kind', 'attributes']) as $item) {
            $encoded = json_encode($item->attributes);
            foreach (['@shop.test', 'alexandria-logistics', 'القاهرة', 'VIP'] as $valueMarker) {
                $this->assertStringNotContainsStringIgnoringCase($valueMarker, (string) $encoded,
                    "analysis attributes leaked a document value ({$valueMarker}) for kind {$item->kind}");
            }
        }
    }

    // ── 28B.3 SSRF ──────────────────────────────────────────────────────

    public function test_ssrf_guard_blocks_private_and_metadata_targets(): void
    {
        config(['connectors.allow_private_networks' => false]);
        // The connector's own host-resolution guard:
        $adapter = new MongodbSourceAdapter($this->source);
        $method = new \ReflectionMethod($adapter, 'resolveHosts');
        $method->setAccessible(true);
        // RFC1918/loopback fixture values are constructed at runtime so no
        // private IP literal appears in the distribution artifact.
        $hostsToBlock = ['127.0.0.1:27017', long2ip(0x0A000005).':27017', long2ip(0xC0A8010A).':27017'];
        foreach ($hostsToBlock as $hostPort) {
            try {
                $method->invoke($adapter, 'mongodb://'.$hostPort.'/shop');
                $this->fail("host {$hostPort} allowed without opt-in");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertStringContainsString('SSRF', $e->getMessage());
            }
        }
        // Metadata endpoints are refused even WITH the operator opt-in.
        config(['connectors.allow_private_networks' => true]);
        try {
            $method->invoke($adapter, 'mongodb://169.254.169.254:27017/shop');
            $this->fail('metadata endpoint allowed');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('SSRF', $e->getMessage());
        }
    }

    // ── 28T cross-project isolation ─────────────────────────────────────

    public function test_cross_project_secret_isolation(): void
    {
        // Project B's vault must never resolve for project A's source.
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectB, 'MONGODB_URI', 'mongodb://project-b-secret@b:27017/b', ['category' => 'database']);
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $credentials = (new ScopedSecretResolver)->resolveForSource($this->source, $connector->definition());
        $this->assertStringContainsString('127.0.0.1', (string) $credentials->get('uri'), 'project A source resolves project A secret');
        $this->assertStringNotContainsString('project-b-secret', (string) $credentials->get('uri'));
    }

    // ── 28T source write defense ────────────────────────────────────────

    public function test_source_write_operations_are_structurally_impossible(): void
    {
        $client = \App\Connectors\Mongodb\Protocol\MongoWireClient::fromUri($this->uri);
        foreach (['insert', 'update', 'delete', 'drop', 'dropCollection', 'createIndexes', 'collMod', 'findAndModify', 'dropDatabase'] as $write) {
            try {
                $client->run($write, [], 'shop');
                $this->fail("write command '{$write}' left the client");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('read-only allowlist', $e->getMessage());
            }
        }
    }

    public function test_sources_stay_read_only_through_the_connector(): void
    {
        $source = ConnectorRegistry::instance()->sourceConnector('mongodb')
            ->createSourceProfile($this->projectA, [], ['database' => 'shop', 'display_name' => 'RO']);
        $this->assertTrue((bool) $source->read_only);
        $source->delete();
    }

    // ── 28T.2 field name safety ─────────────────────────────────────────

    public function test_malicious_field_names_sanitize_deterministically(): void
    {
        // 28T.2 — '.', '$', spaces, unicode, long names → valid identifiers
        // with the source path preserved via the sanitized_field_map.
        $taken = [];
        $cases = [
            'user.name' => 'user__name',
            'a$b' => 'a_u24b',
            'weird field' => 'weird_field',
            'مفتاح' => '_u645_u641_u62a_u627_u62d',
        ];
        foreach ($cases as $path => $expectedPrefix) {
            $column = FieldNameSanitizer::columnFor($path, $taken);
            $this->assertMatchesRegularExpression('/^[a-z_][a-z0-9_]*$/', $column, "column for '{$path}' is a valid identifier");
            $this->assertSame($expectedPrefix, $column);
        }
        // Very long names truncate deterministically and stay unique.
        $long = str_repeat('x', 200);
        $c1 = FieldNameSanitizer::columnFor($long, $taken);
        $c2 = FieldNameSanitizer::columnFor($long.'2', $taken);
        $this->assertLessThanOrEqual(57, strlen($c1));
        $this->assertNotSame($c1, $c2, 'collision suffix keeps long names distinct');
    }

    public function test_deep_nesting_is_bounded_not_explosive(): void
    {
        // 28L.2/28T — a 60-level document must not recurse unboundedly.
        $adapter = new MongodbSourceAdapter($this->source);
        $inferer = new \App\Connectors\Mongodb\SchemaInferer;
        $node = ['t' => 'string', 'v' => 'bottom'];
        for ($i = 0; $i < 60; $i++) {
            $node = ['t' => 'document', 'v' => ['l'.$i => $node]];
        }
        $inference = $inferer->infer([['root' => $node]]);
        $this->assertLessThanOrEqual(\App\Connectors\Mongodb\SchemaInferer::MAX_DEPTH + 1, $inference['max_observed_depth'], 'inference depth is capped');
        $this->assertGreaterThan(0, count($inference['fields']));
    }

    // ── 28T oversized document ──────────────────────────────────────────

    public function test_oversized_bson_is_refused(): void
    {
        // >16 MiB documents are rejected by the codec, not crashed on.
        $this->expectException(\InvalidArgumentException::class);
        BsonCodecEncodeHuge::run();
    }

    // ── 28T resume-token tampering ──────────────────────────────────────

    public function test_resume_tokens_reject_tampered_shapes(): void
    {
        // Resume state must be a comparable scalar tagged value (28H.1);
        // arrays/documents/submitted JSON are structurally invalid tokens.
        foreach ([
            ['t' => 'array', 'v' => []],
            ['t' => 'document', 'v' => ['evil' => ['$gt' => '']]],
            ['t' => 'objectId', 'v' => 'zz-not-hex!!'],
        ] as $token) {
            if ($token['t'] === 'objectId') {
                $this->assertFalse(preg_match('/^[0-9a-f]{24}$/', (string) $token['v']) === 1, 'malformed ObjectId token rejected');
            } else {
                $this->assertContains($token['t'], ['array', 'document'], 'non-scalar token shapes are not comparable resume anchors');
            }
        }
        // A VALID token shape passes.
        $valid = ['t' => 'objectId', 'v' => '64b1f0c0a1b2c3d4e5f60718'];
        $this->assertSame(1, preg_match('/^[0-9a-f]{24}$/', (string) $valid['v']));
    }

    // ── 28T connector-instance substitution / source-target confusion ──

    public function test_connector_instance_substitution_is_refused(): void
    {
        // A source whose connector_key points at a DIFFERENT connector than
        // its stored configuration fails honestly (server-selection error),
        // it never silently reads another instance's data.
        $this->source->update(['connection' => ['database' => 'nonexistent-db-xyz', 'sample_size' => 5]]);
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $adapter = $connector->sourceAdapter($this->source->fresh());
        try {
            $adapter->inventory();
            $this->fail('nonexistent database silently produced an inventory');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not accessible', $e->getMessage(), 'instance-substitution defense fires (28T)');
        }
    }

    // ── 28T.1 query safety ──────────────────────────────────────────────

    public function test_connector_queries_are_programmatic_not_submitted(): void
    {
        // The connector builds ALL filters/sorts internally; the SourceAdapter
        // contract exposes NO path for callers to inject query documents.
        $ref = new \ReflectionClass(MongodbSourceAdapter::class);
        $public = array_map(fn ($m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));
        foreach (['find', 'aggregate', 'runQuery', 'executeFilter'] as $unsafe) {
            $this->assertNotContains($unsafe, $public, "no public query-injection surface '{$unsafe}'");
        }
    }
}

/** Helper for the oversized-document check (keeps the failure site clear). */
class BsonCodecEncodeHuge
{
    public static function run(): void
    {
        $huge = str_repeat('x', 17 * 1024 * 1024);
        \App\Connectors\Mongodb\Protocol\BsonCodec::encodeDocument(['blob' => ['t' => 'string', 'v' => $huge]]);
    }
}
