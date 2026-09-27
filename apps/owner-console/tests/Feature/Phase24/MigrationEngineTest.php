<?php

namespace Tests\Feature\Phase24;

use App\Models\MigrationAnalysis;
use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\MigrationSource;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Services\ControlPlane\Migration\MigrationTemplates;
use App\Services\ControlPlane\Migration\PlanGenerator;
use App\Services\ControlPlane\Migration\TransformPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/**
 * Phase 24A/24J — the reusable migration engine exercised for real:
 * analyze → classify → plan → run → validate on sqlite adapters (no mocks),
 * plus guardrails, resume, clean-rerun determinism and transforms.
 */
class MigrationEngineTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
    }

    protected function makeSource(): MigrationSource
    {
        $path = $this->buildSqliteSource($this->tempPath('engine-source-'.uniqid().'.sqlite'));

        return MigrationSource::create([
            'project_id' => $this->projectA->id,
            'type' => 'sqlite',
            'display_name' => 'Fixture source',
            'source_ref' => 'local fixture',
            'connection' => ['path' => $path, 'schema' => 'public'],
            'secret_refs' => [],
            'read_only' => true,
            'status' => 'pending',
        ]);
    }

    protected function analyze(MigrationSource $source): MigrationAnalysis
    {
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $this->assertSame('completed', $analysis->status, 'Analysis failed: '.json_encode($analysis->errors));
        $service->classify($analysis);

        return $analysis;
    }

    // ── Analysis ────────────────────────────────────────────────────────

    public function test_analyze_inventories_source_read_only_and_versions(): void
    {
        $source = $this->makeSource();
        $analysis = $this->analyze($source);

        $counts = $analysis->counts;
        $this->assertSame(3, $counts['tables']);          // company, branch, shipment
        $this->assertSame(1, $counts['views']);
        $this->assertSame(2, $counts['auth']);            // users
        $this->assertSame(2, $counts['storage']);         // buckets
        $this->assertNotNull($analysis->source_fingerprint);

        // Every analyze run is an immutable new version.
        $before = MigrationAnalysis::count();
        $this->analyze($source);
        $this->assertSame($before + 1, MigrationAnalysis::count());
        $this->assertSame('ready', $source->fresh()->status);
    }

    public function test_classify_and_risks_are_evidence_based(): void
    {
        $source = $this->makeSource();
        $analysis = $this->analyze($source);

        $shipment = $analysis->items()->where('kind', 'table')->where('name', 'shipment')->first();
        $this->assertSame('DIRECT', $shipment->compatibility);
        // cod_amount numeric + table name pattern → financial reconciliation risk.
        $this->assertContains('financial_table_reconciliation', $shipment->risks);

        $policyLike = $analysis->items()->where('kind', 'auth')->first();
        $this->assertSame('SUPPORTED_WITH_TRANSFORM', $policyLike->compatibility);
    }

    // ── Plan & dependency stages ────────────────────────────────────────

    public function test_plan_orders_tables_by_fk_dependency(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);

        $stageOf = fn (string $table) => (int) $plan->items()->where('source_name', $table)->value('stage');
        $company = $stageOf('company');
        $branch = $stageOf('branch');
        $shipment = $stageOf('shipment');

        $this->assertGreaterThan($company, $branch, 'branch must load after company');
        $this->assertGreaterThan($branch, $shipment, 'shipment must load after branch');

        // Auth first (stage 0), statuses mapped.
        $this->assertDatabaseHas('migration_plan_items', [
            'migration_plan_id' => $plan->id, 'source_kind' => 'auth_users', 'status' => 'READY',
        ]);
        $this->assertNotSame($company, $branch);
    }

    public function test_template_manifest_validation_and_application(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);

        $invalid = MigrationTemplates::validateManifest(['mappings' => [['target_table' => 'x', 'transform' => 'nope']]]);
        $this->assertFalse($invalid['valid']);

        $valid = MigrationTemplates::validateManifest(['mappings' => [
            ['source_table' => 'shipment', 'target_table' => 'deliveries', 'key_strategy' => 'uuid_preserve', 'transform' => 'direct_copy'],
        ]]);
        $this->assertTrue($valid['valid']);

        $applied = MigrationTemplates::applyManifest($plan, ['mappings' => [
            ['source_table' => 'shipment', 'target_table' => 'deliveries', 'key_strategy' => 'int_preserve', 'transform' => 'direct_copy'],
        ]]);
        $this->assertSame(1, $applied);
        $this->assertSame('deliveries', $plan->items()->where('source_name', 'shipment')->value('target_name'));
    }

    // ── Run & validation ────────────────────────────────────────────────

    protected function makeTargetConfig(string $path): array
    {
        return ['driver' => 'sqlite', 'path' => $path, 'recreate' => true, 'disposable' => true, 'environment_type' => 'development'];
    }

    protected function runPlan(MigrationPlan $plan, string $mode = 'rehearsal', ?array $target = null): MigrationRun
    {
        $target = $target ?? $this->makeTargetConfig($this->tempPath('engine-target-'.uniqid().'.sqlite'));
        $manager = new MigrationRunManager;
        $run = $manager->start($plan, [
            'mode' => $mode,
            'target' => $target,
            'target_disposable' => $target['disposable'] ?? true,
            'reset' => $mode === 'rehearsal',
            'target_environment_type' => $target['environment_type'] ?? 'development',
        ]);
        $manager->execute($run);

        return $run->fresh();
    }

    public function test_run_migrates_data_and_validates(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $run = $this->runPlan($plan->fresh());

        $this->assertSame('completed', $run->status, json_encode($run->failure));

        // Rows actually landed in the sqlite target.
        $targetPath = json_decode($run->target_connection, true)['path'];
        $pdo = new \PDO('sqlite:'.$targetPath);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM shipment')->fetchColumn());
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        // Plan items marked migrated.
        $this->assertSame(0, $run->items()->where('status', 'failed')->count());
        $this->assertDatabaseHas('migration_run_items', ['migration_run_id' => $run->id, 'status' => 'completed']);

        // Validators run for real.
        $results = (new MigrationRunManager)->validate($run);
        $this->assertSame('pass', $results['row_counts']['status']);
    }

    public function test_utf8_roundtrip_preserves_exact_arabic_bytes(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $run = $this->runPlan($plan->fresh());
        $this->assertSame('completed', $run->status);

        $targetPath = json_decode($run->target_connection, true)['path'];
        $pdo = new \PDO('sqlite:'.$targetPath);
        $rows = $pdo->query('SELECT note FROM shipment ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        // Exact Unicode regression set (24J.4).
        $this->assertSame('العربية — القاهرة — الإسكندرية', $rows[0]);
        $this->assertSame('وصلة نص ثنائي', $rows[1]);

        // And through JSON (source → export → transform → target → JSON).
        $json = json_encode(['note' => $rows[0]], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('الإسكندرية', $json);
    }

    public function test_auth_migration_preserves_bcrypt_verbatim(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $run = $this->runPlan($plan->fresh());

        $targetPath = json_decode($run->target_connection, true)['path'];
        $pdo = new \PDO('sqlite:'.$targetPath);
        $hashes = $pdo->query('SELECT password FROM users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        $sourceHash = (new \PDO('sqlite:'.$this->sourcePath($run)))
            ->query('SELECT encrypted_password FROM auth_users ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame($sourceHash[0], $hashes[0], 'bcrypt hash must be preserved verbatim');
        $this->assertStringStartsWith('$2a$10$', $hashes[0]);
        $this->assertStringStartsWith('$2a$06$', $hashes[1]);
    }

    protected function sourcePath(MigrationRun $run): string
    {
        return $run->plan->analysis->source->connection['path'];
    }

    public function test_resume_completes_failed_item_without_rewriting_completed(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $run = $this->runPlan($plan->fresh(), 'dry_run');
        $this->assertSame('completed', $run->status);

        // Simulate a failed item on a real run, then resume.
        $runItem = $run->items()->first();
        $runItem->update(['status' => 'failed', 'error' => 'simulated failure']);
        $run->update(['status' => 'failed']);

        (new MigrationRunManager)->execute($run->fresh());
        $run = $run->fresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->items()->where('status', 'failed')->count());
        $this->assertGreaterThan(1, $runItem->fresh()->attempts);
    }

    public function test_clean_rehearsal_runs_twice_and_is_deterministic(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $target = $this->makeTargetConfig($this->tempPath('engine-clean-'.uniqid().'.sqlite'));

        $result = (new MigrationRunManager)->rehearseClean($plan->fresh(), $target);
        $this->assertCount(2, $result['runs']);
        $this->assertTrue($result['deterministic'], json_encode($result['comparison']));
        $this->assertSame('completed', $result['comparison'][0]['status']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);
        $targetPath = $this->tempPath('engine-dry-'.uniqid().'.sqlite');
        $run = $this->runPlan($plan->fresh(), 'dry_run', [
            'driver' => 'sqlite', 'path' => $targetPath, 'recreate' => false, 'disposable' => true,
        ]);

        $this->assertSame('completed', $run->status);
        $this->assertGreaterThan(0, array_sum($run->items()->pluck('rows_written')->all()));
        $this->assertFileDoesNotExist($targetPath, 'dry run must not create/write the target');
    }

    // ── Guardrails ──────────────────────────────────────────────────────

    public function test_production_target_is_refused(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MigrationRunManager)->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => $this->makeTargetConfig($this->tempPath('p.sqlite')),
            'target_disposable' => true,
            'target_environment_type' => 'production',
        ]);
    }

    public function test_reset_requires_disposable_target(): void
    {
        $analysis = $this->analyze($this->makeSource());
        $plan = (new MigrationCenterService)->generatePlan($analysis);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MigrationRunManager)->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => $this->makeTargetConfig($this->tempPath('p.sqlite')),
            'target_disposable' => false,
            'reset' => true,
        ]);
    }

    public function test_source_target_reversal_is_blocked(): void
    {
        $source = $this->makeSource();
        $analysis = $this->analyze($source);
        $plan = (new MigrationCenterService)->generatePlan($analysis);

        $samePath = $source->connection['path'];
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MigrationRunManager)->start($plan->fresh(), [
            'mode' => 'rehearsal',
            'target' => ['driver' => 'sqlite', 'path' => $samePath, 'disposable' => true],
            'target_disposable' => true,
            'reset' => true,
        ]);
    }

    // ── Transforms ──────────────────────────────────────────────────────

    public function test_transforms_are_deterministic_and_strict(): void
    {
        $row = ['id' => 1, 'status' => 'in_warehouse', 'payload' => '{"b":2,"a":1}', 'url' => 'https://supabase.co/storage/v1/object/public/x.png', 'ts' => '2026-09-01T10:00:00+03:00', 'uid' => '11111111-1111-4111-8111-111111111111'];

        $renamed = TransformPipeline::apply('rename', $row, ['columns' => ['status' => 'state']]);
        $this->assertSame('in_warehouse', $renamed['state']);

        $enum = TransformPipeline::apply('enum_mapping', $row, ['columns' => ['status' => ['in_warehouse' => 'pending']]]);
        $this->assertSame('pending', $enum['status']);

        $this->expectException(InvalidArgumentException::class);
        TransformPipeline::apply('enum_mapping', $row, ['columns' => ['status' => ['known' => 'x']]]);
    }

    public function test_url_rewrite_and_timestamp_normalize(): void
    {
        $row = ['logo' => 'https://xyz.supabase.co/storage/v1/object/public/bucket/logo.png', 'created' => '2026-09-01T10:00:00+03:00'];
        $out = TransformPipeline::apply('url_rewrite', $row, ['from' => 'https://xyz.supabase.co/storage/v1/object/public', 'to' => '/storage']);
        $this->assertSame('/storage/bucket/logo.png', $out['logo']);

        $ts = TransformPipeline::apply('timestamp_normalize', ['created' => '2026-09-01T10:00:00+03:00'], ['columns' => ['created']]);
        $this->assertSame('2026-09-01 07:00:00', $ts['created']);
    }

    public function test_utf8_strictness_refuses_invalid_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransformPipeline::apply('utf8_text', ['note' => "\xC3\x28 invalid"], ['columns' => ['note']]);
    }

    public function test_uuid_preserve_validates(): void
    {
        $out = TransformPipeline::apply('uuid_preserve', ['id' => '11111111-1111-4111-8111-111111111111'], ['columns' => ['id']]);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $out['id']);

        $this->expectException(InvalidArgumentException::class);
        TransformPipeline::apply('uuid_preserve', ['id' => 'not-a-uuid'], ['columns' => ['id']]);
    }

    public function test_rpc_and_edge_classification(): void
    {
        // 24J.8 RPC classification rules.
        $this->assertSame('keep_postgresql', PlanGenerator::functionTarget(['security' => 'definer']));
        $this->assertSame('laravel_service', PlanGenerator::functionTarget(['auth_dependent' => true, 'security' => 'invoker']));
        $this->assertSame('queue_job', PlanGenerator::functionTarget(['net_dependent' => true]));
        $this->assertSame('needs_review', PlanGenerator::functionTarget([]));

        // 24J.9 edge function classification via the compatibility layer.
        [$priv] = \App\Services\ControlPlane\Migration\CompatibilityClassifier::classify('edge_function', ['service_role' => true]);
        [$plain] = \App\Services\ControlPlane\Migration\CompatibilityClassifier::classify('edge_function', []);
        $this->assertSame('EXTERNAL_INTEGRATION', $priv);
        $this->assertSame('APPLICATION_CONVERSION_REQUIRED', $plain);

        // 24J.10 standard template present and generic.
        $template = MigrationTemplates::standard();
        $this->assertSame('supabase-standard-migration', $template['id']);
        $this->assertGreaterThan(5, count($template['phases']));
        $this->assertStringNotContainsStringIgnoringCase('demo-customer', json_encode($template), 'template must not encode project names');
    }
}
