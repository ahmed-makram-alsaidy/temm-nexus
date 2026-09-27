<?php

namespace Tests\Feature\Phase28;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\TestCase;

/**
 * Phase 28V — REAL MongoDB dogfood (28V.1-28V.7): a disposable mongo:7
 * container seeded with the synthetic `shop` database (Arabic samples,
 * Decimal128, nested documents, order item arrays, validators, TTL/unique
 * indexes, GridFS pair, schema variance) migrated through the FULL pipeline
 * into a disposable PostgreSQL target. Skips honestly when Docker or the
 * images are unavailable — never fakes.
 */
class MongodbDockerDogfoodTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase27Fixture;

    protected ?string $mongoContainer = null;
    protected ?string $pgContainer = null;
    protected int $mongoPort = 0;
    protected int $pgPort = 0;

    protected function tearDown(): void
    {
        foreach ([$this->mongoContainer, $this->pgContainer] as $container) {
            if ($container !== null) {
                exec('docker rm -f '.escapeshellarg($container).' 2>/dev/null');
            }
        }
        parent::tearDown();
    }

    protected function dockerAvailable(): bool
    {
        exec('docker version --format ok 2>'.escapeshellarg('nul'), $out, $code);

        return ($out[0] ?? '') === 'ok';
    }

    protected function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        return $port;
    }

    /** Compact 28V.1 synthetic seed (mongosh JS). */
    protected function seedScript(): string
    {
        $oid = fn (int $n) => 'ObjectId("'.str_pad(dechex($n), 24, '0', STR_PAD_LEFT).'")';
        $users = [];
        $names = ['القاهرة للتوصيل', 'alexandria-logistics', 'إدارة الطلبات', 'وصلة فرع', 'Cairo User'];
        foreach (range(1, 8) as $i) {
            $users[] = sprintf(
                '{_id: %s, name: %s, email: "user%d@shop.test", age: %d, is_active: %s, balance: NumberDecimal("19.99%d"), created_at: new Date(Date.UTC(2025,0,%02d)), address: {city: "القاهرة", street: "شارع التحرير %d", geo: {lat: 30.04%d, lng: 31.23}}, tags: ["vip", "الإسكندرية"]}',
                $oid($i), json_encode($names[$i % 5], JSON_UNESCAPED_UNICODE), $i, 20 + $i, $i % 2 === 0 ? 'true' : 'false', $i % 10, $i, $i, $i
            );
        }
        $products = [];
        foreach (range(1, 6) as $i) {
            $products[] = sprintf('{_id: %s, sku: "SKU-%d", title: "منتج %d", price: NumberDecimal("1234.5678"), stock: %d}', $oid(100 + $i), $i, $i, $i * 10);
        }
        $orders = [];
        foreach (range(1, 6) as $i) {
            $orders[] = sprintf(
                '{_id: %s, user_id: %s, total: NumberDecimal("250.75"), currency: "EGP", status: "shipped", created_at: new Date(Date.UTC(2025,1,%02d)), items: [{product_id: %s, qty: %d, price: NumberDecimal("12.34"), note: "وصلة"}, {product_id: %s, qty: %d, price: NumberDecimal("56.78")}]}',
                $oid(500 + $i), $oid(($i % 8) + 1), ($i % 28) + 1, $oid(100 + (($i % 6) + 1)), $i, $oid(100 + (($i % 5) + 2)), $i + 1
            );
        }
        $events = [];
        foreach (range(1, 10) as $i) {
            $events[] = sprintf(
                '{_id: %s, kind: "page_view", payload: {url: "/page/%d", ms: %d}, created_at: new Date(Date.UTC(2025,0,1)), expires_at: new Date(Date.UTC(2025,1,1))}',
                $oid(900 + $i), $i, $i * 7
            );
        }
        $mixed = [
            '{_id: '.$oid(701).', value: "string-shape", score: 1}',
            '{_id: '.$oid(702).', value: 42, score: 2}',
            '{_id: '.$oid(703).', value: {nested: "object-shape"}, score: 3}',
            '{_id: '.$oid(704).', score: 4}',
            '{_id: '.$oid(705).', value: null, score: 5}',
            '{_id: '.$oid(710).', l1: {l2: {l3: {l4: {l5: {l6: {l7: {l8: {l9: {l10: "bottom"}}}}}}}}}}',
        ];

        return implode("\n", [
            'db.users.insertMany(['.implode(',', $users).']);',
            'db.createCollection("products", {validator: {$jsonSchema: {bsonType: "object", required: ["sku", "price"]}}, validationLevel: "moderate"});',
            'db.products.insertMany(['.implode(',', $products).']);',
            'db.orders.insertMany(['.implode(',', $orders).']);',
            'db.events.insertMany(['.implode(',', $events).']);',
            'db.mixed_documents.insertMany(['.implode(',', $mixed).']);',
            'db.users.createIndex({email: 1}, {unique: true});',
            'db.orders.createIndex({user_id: 1});',
            'db.events.createIndex({expires_at: 1}, {expireAfterSeconds: 0});',
            'db["photos.files"].insertOne({_id: '.$oid(801).', filename: "صورة.png", length: 1024, md5: "d41d8cd98f00b204e9800998ecf8427e", uploadDate: new Date(Date.UTC(2025,0,1))});',
            'db["photos.chunks"].insertOne({_id: '.$oid(811).', files_id: '.$oid(801).', n: 0, data: BinData(0, "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")});',
        ]);
    }

    public function test_real_mongodb_dogfood_full_pipeline_to_postgres_target(): void
    {
        if (! $this->dockerAvailable()) {
            $this->markTestSkipped('Docker unavailable — dogfood requires a disposable local MongoDB container (28V)');
        }
        // 28B.3 — loopback containers require the explicit operator opt-in.
        config(['connectors.allow_private_networks' => true]);

        // ── 28V — disposable MongoDB container ──────────────────────────
        $this->mongoPort = $this->freePort();
        $this->mongoContainer = 'p28-mongo-dogfood-'.getmypid();
        exec('docker run --rm -d --name '.escapeshellarg($this->mongoContainer).' -p 127.0.0.1:'.$this->mongoPort.':27017 mongo:7 2>&1', $out, $code);
        if ($code !== 0) {
            $this->mongoContainer = null;
            $this->markTestSkipped('mongo:7 container failed to start: '.implode(' ', array_slice($out, 0, 3)));
        }

        // ── Disposable PostgreSQL target (28V.2) ────────────────────────
        $this->pgPort = $this->freePort();
        $this->pgContainer = 'p28-pg-dogfood-'.getmypid();
        exec('docker run --rm -d --name '.escapeshellarg($this->pgContainer).' -e POSTGRES_PASSWORD=p28dogfood -e POSTGRES_DB=dogfood_target -p 127.0.0.1:'.$this->pgPort.':5432 postgres:17-alpine 2>&1', $pgOut, $pgCode);
        if ($pgCode !== 0) {
            $this->pgContainer = null;
            $this->markTestSkipped('postgres:17-alpine container failed to start: '.implode(' ', array_slice($pgOut, 0, 3)));
        }

        // Wait for MongoDB readiness (poll hello over the wire protocol).
        $uri = 'mongodb://127.0.0.1:'.$this->mongoPort.'/shop';
        $ready = false;
        for ($i = 0; $i < 60; $i++) {
            try {
                $client = \App\Connectors\Mongodb\Protocol\MongoWireClient::fromUri($uri, 3000);
                $client->connect();
                $client->close();
                $ready = true;
                break;
            } catch (\Throwable) {
                sleep(1);
            }
        }
        if (! $ready) {
            $this->markTestSkipped('mongo:7 container did not become ready in 60s');
        }

        // Seed the synthetic shop database (28V.1) — piped via stdin (the
        // script exceeds the Windows command-line length limit).
        $seedFile = storage_path('framework/testing/phase28/seed-'.uniqid().'.js');
        file_put_contents($seedFile, $this->seedScript());
        exec('docker exec -i '.escapeshellarg($this->mongoContainer).' mongosh shop --quiet < '.escapeshellarg($seedFile).' 2>&1', $seedOut, $seedCode);
        @unlink($seedFile);
        $this->assertSame(0, $seedCode, 'seed failed: '.implode("\n", array_slice($seedOut, 0, 5)));

        $this->buildPhase27();
        $this->actingAs($this->admin);

        // ── Pipeline: registry → connector → test → discover ───────────
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $test = $connector->testConnection(
            \App\Services\ControlPlane\Connectors\ConnectorCredentials::fromArray(['uri' => $uri, 'database' => 'shop'], ['uri'])
        );
        $this->assertSame('PASS', $test->result, $test->detail);

        // The secret URI carries no credentials here (local container) —
        // store it as the source's vault ref anyway (production parity).
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'MONGODB_URI', $uri, ['category' => 'database']);
        $source = MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'mongodb', 'connector_key' => 'mongodb',
            'display_name' => 'Docker dogfood shop',
            'connection' => ['database' => 'shop', 'sample_size' => 100],
            'secret_refs' => ['uri' => 'MONGODB_URI'],
            'read_only' => true, 'status' => 'pending',
        ]);

        // Fingerprint BEFORE (28V.3).
        $fingerprintBefore = $connector->fingerprint($source);

        // ── Analyze → classify → plan (28E-28I) ─────────────────────────
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);
        $plan = $service->generatePlan($analysis);
        $plannedNames = $plan->items()->where('source_kind', 'table')->pluck('source_name')->all();
        $this->assertContains('users', $plannedNames);
        $this->assertContains('orders__items', $plannedNames, 'array-of-objects split to child table (28L.1)');
        $this->assertNotContains('photos.files', $plannedNames, 'GridFS not treated as a table (28N)');

        // ── Run against the disposable PostgreSQL target (28V.2) ────────
        \App\Services\ControlPlane\SecretVaultService::createSecret($this->projectA, 'DOGFOOD_PG_PASSWORD', 'p28dogfood', ['category' => 'database']);
        $targetPath = '';
        $manager = new MigrationRunManager;
        $run = $manager->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => [
                'driver' => 'postgres',
                'host' => '127.0.0.1', 'port' => $this->pgPort, 'database' => 'dogfood_target',
                'username' => 'postgres',
                'secret_refs' => ['password' => 'DOGFOOD_PG_PASSWORD'],
            ],
            'target_disposable' => true, 'reset' => true, 'target_environment_type' => 'development',
        ]);
        // Wait for PostgreSQL readiness (poll a trivial connect via the manager).
        $pgReady = false;
        for ($i = 0; $i < 60; $i++) {
            try {
                $probe = @fsockopen('127.0.0.1', $this->pgPort, $errno, $errstr, 1);
                if ($probe !== false) {
                    fclose($probe);
                    $pgReady = true;
                    break;
                }
            } catch (\Throwable) {
            }
            sleep(1);
        }
        if (! $pgReady) {
            $this->markTestSkipped('postgres target did not become ready in 60s');
        }
        sleep(2); // postgres init DB is created after the port opens
        $manager->execute($run);
        $run = $run->fresh();
        $itemErrors = $run->items()->where('status', 'failed')->pluck('error')->all();
        $this->assertSame('completed', $run->status, 'item errors: '.json_encode($itemErrors));

        // ── Validate (28Q) ────────────────────────────────────────────────
        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }

        // 28V.6 — Decimal128 exactness in PostgreSQL.
        $pg = new \PDO('pgsql:host=127.0.0.1;port='.$this->pgPort.';dbname=dogfood_target', 'postgres', 'p28dogfood');
        $total = $pg->query("SELECT total FROM orders WHERE _id = '0000000000000000000001f5'")->fetchColumn();
        $this->assertSame('250.75', (string) $total, 'Decimal128 exact through PG (28V.6)');

        // 28V.7 — Arabic exact roundtrip into PostgreSQL.
        $arabic = $pg->query("SELECT name FROM users WHERE _id = '000000000000000000000005'")->fetchColumn();
        $this->assertSame('القاهرة للتوصيل', (string) $arabic, 'Arabic UTF-8 exact (28V.7)');

        // 28V.5 — JSONB structural preservation.
        $mixed = json_decode((string) $pg->query("SELECT document FROM mixed_documents WHERE _id = '0000000000000000000002bd'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('string-shape', $mixed['value']['v'] ?? $mixed['value'] ?? null, 'variant preserved (28V.5)');

        // 28V.4 — child table FK integrity.
        $orphans = (int) $pg->query('SELECT COUNT(*) FROM orders__items ci LEFT JOIN orders o ON o._id = ci.parent_id WHERE o._id IS NULL')->fetchColumn();
        $this->assertSame(0, $orphans);

        // 28V.3 — source fingerprint UNCHANGED.
        $fingerprintAfter = $connector->fingerprint($source->fresh());
        $this->assertSame($fingerprintBefore, $fingerprintAfter, 'SOURCE MUTATION DETECTED (28V.3)');
    }
}
