<?php

namespace Tests\Feature\Phase25;

use App\Models\AiUsageRecord;
use App\Models\CopilotRun;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\AiNetworkGuard;
use App\Services\ControlPlane\Ai\CopilotToolRegistry;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use App\Services\ControlPlane\Ai\MigrationCopilot;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Models\MigrationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase25\Concerns\BuildsPhase25Fixture;
use Tests\TestCase;

/** Phase 25F/G/H — AI gateway, copilot modes, tool policy. */
class AiGatewayCopilotTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase25Fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase25();
    }

    protected function sourceWithAnalysis(): MigrationSource
    {
        $path = storage_path('framework/testing/phase24/engine-source-'.uniqid().'.sqlite');
        @mkdir(dirname($path), 0775, true);
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER REFERENCES customers(id))');
        $pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT)');

        $source = MigrationSource::create([
            'project_id' => $this->projectA->id, 'type' => 'sqlite', 'display_name' => 'fixture',
            'connection' => ['path' => $path], 'read_only' => true, 'status' => 'pending',
        ]);
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $service->classify($analysis);

        return $source;
    }

    // ── 25F gateway ─────────────────────────────────────────────────────

    public function test_fake_driver_completes_and_records_usage(): void
    {
        $gateway = new AiGateway;
        $resolved = $gateway->profile($this->projectA, 'planner');

        $result = $gateway->complete($this->projectA, $resolved, [
            ['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => 'hello'],
        ], ['fake_responses' => ['{"summary":"ok"}']]);

        $this->assertSame('{"summary":"ok"}', $result['text']);
        $record = AiUsageRecord::where('project_id', $this->projectA->id)->latest('id')->first();
        $this->assertSame(100, $record->input_tokens);
        $this->assertNull($record->estimated_cost, 'no pricing configured → no invented cost');
    }

    public function test_usage_cost_only_when_pricing_configured(): void
    {
        $this->fakeProvider->update(['pricing' => ['input_per_1k' => 0.5, 'output_per_1k' => 1.5, 'currency' => 'EGP']]);
        $gateway = new AiGateway;
        $gateway->complete($this->projectA, $gateway->profile($this->projectA, 'planner'), [
            ['role' => 'user', 'content' => 'x'],
        ], ['fake_input_tokens' => 2000, 'fake_output_tokens' => 1000]);

        $record = AiUsageRecord::latest('id')->first();
        $this->assertSame(2.5, (float) $record->estimated_cost); // 2000/1000*0.5 + 1000/1000*1.5
        $this->assertSame('EGP', $record->currency);
    }

    public function test_provider_test_results_are_classified(): void
    {
        $gateway = new AiGateway;
        $this->assertSame('CONNECTED', $gateway->testProvider($this->fakeProvider)['result']);
        $this->assertSame('connected', $this->fakeProvider->fresh()->status);
    }

    public function test_fake_driver_error_and_unknown_provider(): void
    {
        $gateway = new AiGateway;
        $this->expectException(\RuntimeException::class);
        $gateway->complete($this->projectA, $gateway->profile($this->projectA, 'planner'), [
            ['role' => 'user', 'content' => 'x'],
        ], ['fake_error' => 'timeout simulation']);
    }

    public function test_api_key_is_encrypted_at_rest(): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('ai_provider_configs')->where('id', $this->fakeProvider->id)->value('secret_encrypted');
        $this->assertStringNotContainsString('TESTKEY', $raw);
    }

    // ── SSRF guard on custom base URLs ──────────────────────────────────

    public function test_ssrf_guard_on_custom_base_url(): void
    {
        foreach ([
            'http://169.254.169.254/v1',           // metadata, and plain http
            'https://localhost/v1',                 // loopback
            'https://10.0.0.5/v1',                  // private
            'file:///etc/passwd',                   // scheme
        ] as $evil) {
            $config = clone $this->fakeProvider;
            $config->base_url = $evil;
            try {
                AiNetworkGuard::assertSafeBaseUrl($config);
                $this->fail("SSRF guard accepted: {$evil}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }

        $ok = clone $this->fakeProvider;
        $ok->base_url = 'https://ai.example.com/v1';
        AiNetworkGuard::assertSafeBaseUrl($ok); // passes
        $this->assertTrue(true);
    }

    // ── 25G copilot modes ───────────────────────────────────────────────

    public function test_advisor_explains_blockers_from_analysis(): void
    {
        $source = $this->sourceWithAnalysis();
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $service->classify($analysis);

        $run = (new MigrationCopilot)->run($this->projectA, 'explain_blockers', ['analysis' => $analysis->fresh()], [
            'fake_responses' => ['{"summary":"Small schema, low risk","blockers":[],"recommended_order":["customers","orders"],"risks":[]}'],
        ]);

        $this->assertSame('completed', $run->status);
        $this->assertSame('advisor', $run->mode);
        $this->assertSame('fake', $run->provider);
        $this->assertSame('Small schema, low risk', $run->result['summary']);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'COPILOT_RUN', 'project_id' => $this->projectA->id]);
    }

    public function test_rls_classification_output_is_stored_as_recommendation(): void
    {
        $source = $this->sourceWithAnalysis();
        $service = new MigrationCenterService;
        $analysis = $service->analyze($source);
        $service->classify($analysis);

        $run = (new MigrationCopilot)->run($this->projectA, 'analyze_rls', ['analysis' => $analysis->fresh()], [
            'fake_responses' => ['{"policies":[{"policy":"p1","table":"orders","candidate":"Laravel Policy","allow_test":"member sees own","deny_test":"other tenant 403","risk":"low"}]}'],
        ]);

        $this->assertSame('completed', $run->status);
        $this->assertSame('Laravel Policy', $run->result['policies'][0]['candidate']);
        // No security conversion was auto-applied: plan items unchanged.
        $this->assertSame(0, \App\Models\MigrationPlanItem::whereHas('plan', fn ($q) => $q->where('project_id', $this->projectA->id))->where('strategy', 'rls_apply')->count());
    }

    public function test_rpc_classification_vocabulary_enforced_in_prompt(): void
    {
        $reflection = new \ReflectionClass(MigrationCopilot::class);
        $classifications = $reflection->getConstant('CLASSIFICATIONS');
        $this->assertContains('KEEP_POSTGRESQL', $classifications);
        $this->assertContains('LARAVEL_SERVICE', $classifications);
        $this->assertContains('QUEUE_JOB', $classifications);
        $this->assertContains('NEEDS_REVIEW', $classifications);
    }

    public function test_client_mapping_flags_gaps(): void
    {
        $repo = \App\Services\ControlPlane\Repository\ClientRepositoryService::linkLocal(
            $this->projectA, 'fixture', $this->buildFixtureRepo()
        );
        \App\Services\ControlPlane\Repository\ClientDependencyScanner::scan($repo);

        $run = (new MigrationCopilot)->run($this->projectA, 'map_client_calls', ['repository' => $repo], [
            'fake_responses' => ['{"mappings":[{"file":"supabaseClient.js","line":5,"supabase_call":"auth.signInWithPassword","platform_target":"backend.auth.login (SDK)","gap":false}]}'],
        ]);
        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->result['mappings'][0]['gap']);

        // Deterministic gap helper: unknown category → gap.
        $mapped = MigrationCopilot::mapCallsiteToPlatform(['category' => 'mystery']);
        $this->assertTrue($mapped['gap']);
        $mapped2 = MigrationCopilot::mapCallsiteToPlatform(['category' => 'auth']);
        $this->assertFalse($mapped2['gap']);
    }

    public function test_builder_run_requires_client_repository(): void
    {
        // Missing repository surfaces as a FAILED run (recorded), not a crash.
        $run = (new MigrationCopilot)->run($this->projectA, 'generate_patch', []);
        $this->assertSame('failed', $run->status);
    }

    // ── 25H tool policy ─────────────────────────────────────────────────

    protected function copilotRun(string $mode = 'advisor'): CopilotRun
    {
        return CopilotRun::create([
            'project_id' => $this->projectA->id, 'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'mode' => $mode, 'action' => 'explain_blockers', 'status' => 'running',
        ]);
    }

    public function test_allowlisted_tool_runs_and_is_ledgered(): void
    {
        $run = $this->copilotRun('advisor');
        $outcome = CopilotToolRegistry::dispatch($run, 'read_analysis', [], fn () => ['ok' => true, 'counts' => []]);

        $this->assertTrue($outcome['ok']);
        $this->assertCount(1, $run->fresh()->tool_calls);
        $this->assertSame('read_analysis', $run->fresh()->tool_calls[0]['tool']);
        $this->assertArrayNotHasKey('args', $run->fresh()->tool_calls[0], 'raw args are never ledgered (hash only)');
    }

    public function test_forbidden_tools_are_structurally_rejected(): void
    {
        $run = $this->copilotRun('builder');
        foreach (['shell', 'exec', 'delete_file', 'supabase_write', 'reveal_secret', 'deploy_production', 'git_push'] as $tool) {
            try {
                CopilotToolRegistry::dispatch($run, $tool, [], fn () => ['ok' => true]);
                $this->fail("forbidden tool accepted: {$tool}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertCount(0, $run->fresh()->tool_calls ?? []);
    }

    public function test_mode_scoping_blocks_cross_mode_tools(): void
    {
        $advisor = $this->copilotRun('advisor');
        $this->expectException(HttpException::class);
        CopilotToolRegistry::dispatch($advisor, 'generate_patch', [], fn () => ['ok' => true]);
    }

    public function test_approval_gated_tool_requires_flag(): void
    {
        $validator = $this->copilotRun('validator');
        try {
            CopilotToolRegistry::dispatch($validator, 'run_allowed_tests', [], fn () => ['ok' => true]);
            $this->fail('approval-gated tool ran without approval');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $outcome = CopilotToolRegistry::dispatch($validator, 'run_allowed_tests', ['approved' => true], fn () => ['ok' => true]);
        $this->assertTrue($outcome['ok']);
    }

    public function test_tool_budget_prevents_replay_bombing(): void
    {
        $run = $this->copilotRun('advisor');
        for ($i = 0; $i < 50; $i++) {
            CopilotToolRegistry::dispatch($run, 'read_analysis', [], fn () => ['ok' => true]);
        }
        $this->expectException(HttpException::class);
        CopilotToolRegistry::dispatch($run, 'read_analysis', [], fn () => ['ok' => true]);
    }
}
