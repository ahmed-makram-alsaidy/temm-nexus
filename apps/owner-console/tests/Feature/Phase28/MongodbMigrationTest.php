<?php

namespace Tests\Feature\Phase28;

use App\Models\MigrationSource;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase27\Concerns\BuildsPhase27Fixture;
use Tests\Feature\Phase28\Concerns\RunsFakeMongoServer;
use Tests\TestCase;

/**
 * Phase 28V.2/28V.4/28V.5/28V.6/28V.7 — the full migration pipeline on the
 * synthetic MongoDB source: analyze → classify → plan → run (sqlite
 * disposable target) → validate, with Decimal exactness, Arabic UTF-8
 * roundtrip, JSONB structural preservation and child-table FK integrity.
 * NO Supabase code participates; the core engine is connector-agnostic.
 */
class MongodbMigrationTest extends TestCase
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
            'display_name' => 'Shop migration source',
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

    public function test_full_pipeline_analyze_plan_migrate_validate(): void
    {
        // ── ANALYZE (28E/28F/28G evidence → normalized analysis) ─────────
        $service = new MigrationCenterService;
        $analysis = $service->analyze($this->source);
        $this->assertSame('completed', $analysis->status, json_encode($analysis->errors));
        $service->classify($analysis);

        // 28I.2 traceability — connector key/version stamped.
        $this->assertSame('mongodb', $analysis->connector_key);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $analysis->connector_version);
        $this->assertNotNull($analysis->source_fingerprint);
        $fingerprintBefore = $analysis->source_fingerprint;

        // Plans: users (RELATIONAL), orders + derived child, JSONB collections.
        $plan = $service->generatePlan($analysis);
        $tableItems = $plan->items()->where('source_kind', 'table')->get();
        $plannedNames = $tableItems->pluck('source_name')->all();
        $this->assertContains('users', $plannedNames);
        $this->assertContains('orders', $plannedNames);
        $this->assertContains('orders__items', $plannedNames);
        $this->assertContains('mixed_documents', $plannedNames);

        // FK-dependency ordering: orders__items and orders AFTER users.
        $stageOf = $tableItems->pluck('stage', 'source_name');
        $this->assertTrue($stageOf['users'] < $stageOf['orders'], 'FK order (28I.1)');

        // ── RUN (28H — batched extraction → disposable sqlite target) ────
        $targetPath = storage_path('framework/testing/phase28/targets/mongo-e2e-'.uniqid().'.sqlite');
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
        $this->assertSame('completed', $run->status, 'item errors: '.json_encode($itemErrors));

        // ── VALIDATE (28Q) ────────────────────────────────────────────────
        $results = $manager->validate($run);
        foreach ($results as $validator => $result) {
            $this->assertContains($result['status'], ['pass', 'warn', 'skipped'], "{$validator}: ".json_encode($result));
        }

        $pdo = new \PDO('sqlite:'.$targetPath);

        // 28V.4 — relational dogfood: users migrated exactly.
        $this->assertSame(8, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $userRow = $pdo->query("SELECT name, email FROM users WHERE _id = '000000000000000000000001'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('alexandria-logistics', $userRow['name']);
        $this->assertSame('user1@shop.test', $userRow['email']);
        $arabicRow = $pdo->query("SELECT name, address__city FROM users WHERE _id = '000000000000000000000005'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('القاهرة للتوصيل', $arabicRow['name'], 'Arabic UTF-8 exact (28V.7)');
        $this->assertSame('القاهرة', $arabicRow['address__city'], 'Arabic nested field exact');

        // 28V.4 — orders + derived child table with FK integrity.
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        $this->assertSame(12, (int) $pdo->query('SELECT COUNT(*) FROM orders__items')->fetchColumn());
        $orphans = (int) $pdo->query('SELECT COUNT(*) FROM orders__items ci LEFT JOIN orders o ON o._id = ci.parent_id WHERE o._id IS NULL')->fetchColumn();
        $this->assertSame(0, $orphans, 'child table FK integrity (28L.1)');
        $orderOrphans = (int) $pdo->query('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u._id = o.user_id WHERE u._id IS NULL')->fetchColumn();
        $this->assertSame(0, $orderOrphans, 'reference integrity users ← orders');

        // 28V.6 — Decimal128 exactness: no float drift through the pipeline.
        $orderTotal = $pdo->query("SELECT total FROM orders WHERE _id = '0000000000000000000001f5'")->fetchColumn();
        $this->assertSame('250.75', (string) $orderTotal, 'Decimal128 exact (28V.6)');
        $userBalance = $pdo->query("SELECT balance FROM users WHERE _id = '000000000000000000000001'")->fetchColumn();
        $this->assertSame('19.991', (string) $userBalance);

        // 28V.5 — JSONB dogfood: mixed_documents preserved structurally.
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM mixed_documents')->fetchColumn());
        $mixedDoc = json_decode((string) $pdo->query("SELECT document FROM mixed_documents WHERE _id = '0000000000000000000002bd'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('string-shape', $mixedDoc['value']['v'] ?? $mixedDoc['value'] ?? null, 'variant shapes preserved in JSONB (28V.5)');

        // 28V.3 — source immutability: fingerprint unchanged after the run.
        $connector = ConnectorRegistry::instance()->sourceConnector('mongodb');
        $fingerprintAfter = $connector->fingerprint($this->source->fresh());
        $this->assertSame($fingerprintBefore, $fingerprintAfter, 'SOURCE MUTATION DETECTED (28V.3 FAIL)');

        // ID coverage (28Q): every source _id landed in the target.
        $adapter = $connector->sourceAdapter($this->source->fresh());
        $adapter->inventory();
        $sourceIds = [];
        $adapter->streamRows('shop', 'users', ['_id'], function ($row) use (&$sourceIds) {
            $sourceIds[] = $row['_id'];
        }, 500);
        foreach ($sourceIds as $id) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE _id = ?');
            $stmt->execute([$id]);
            $this->assertSame(1, (int) $stmt->fetchColumn(), "ID coverage: {$id} missing in target (28Q)");
        }
    }

    public function test_dry_run_never_touches_the_target(): void
    {
        $service = new MigrationCenterService;
        $analysis = $service->analyze($this->source);
        $this->assertSame('completed', $analysis->status);
        $plan = $service->generatePlan($analysis);

        $targetPath = storage_path('framework/testing/phase28/targets/mongo-dry-'.uniqid().'.sqlite');
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }
        $manager = new MigrationRunManager;
        $run = $manager->start($plan->fresh(), [
            'mode' => 'dry_run',
            'target' => ['driver' => 'sqlite', 'path' => $targetPath, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'],
            'target_disposable' => true, 'reset' => false, 'target_environment_type' => 'development',
        ]);
        $manager->execute($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertFileDoesNotExist($targetPath, 'dry run never creates the target');
    }
}
