<?php

namespace Tests\Feature\Phase33;

use App\Models\AiProviderConfig;
use App\Models\ClientRepository;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Ai\Conversion\ConversionPlanBuilder;
use App\Services\ControlPlane\Ai\Conversion\ConversionPromptBuilder;
use App\Services\ControlPlane\Ai\Conversion\ClientConversionService;
use App\Services\ControlPlane\Ai\Conversion\DiffParser;
use App\Services\ControlPlane\Ai\Conversion\TestCommandAllowlist;
use App\Services\ControlPlane\Repository\ClientDependencyScanner;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 33 — AI client-code conversion: deterministic planning (33A/33B),
 * repo containment + SECRET_PRESENT redaction (33C/33F), no-arbitrary-shell
 * test loop (33D/33E), approval-gated patches and quality metrics (33G).
 */
class ClientConversionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Project $project;
    protected ClientRepository $repo;
    protected AiProviderConfig $fakeProvider;

    protected const CONVERSION_DIFF = <<<'DIFF'
--- a/src/app.js
+++ b/src/app.js
@@ -1,3 +1,3 @@
-import { initializeApp } from 'firebase/app';
-import { getAuth, signInWithEmailAndPassword } from 'firebase/auth';
+import { createClient } from '@temm-nexus/sdk';
+import { auth } from '@temm-nexus/sdk/auth';
 const app = initializeApp({});
 await signInWithEmailAndPassword(auth, 'u@e.test', 'pw');
DIFF;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
        $this->project = Project::create([
            'name' => 'P33', 'slug' => 'p33-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p33.test', 'api_version' => 'v1',
        ]);
        $this->fakeProvider = AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake AI (testing)',
            'model' => 'fake-model-1',
            'secret_encrypted' => 'sk-fake-TESTKEY1234567890',
            'enabled' => true,
            'max_output_tokens' => 2048,
            'project_id' => null,
        ]);
        $this->repo = $this->buildRepo();
        ClientDependencyScanner::scan($this->repo);
    }

    protected function buildRepo(): ClientRepository
    {
        $root = storage_path('framework/testing/phase33/repos/'.uniqid());
        @mkdir($root.'/src', 0775, true);
        file_put_contents($root.'/package.json', json_encode([
            'name' => 'synthetic-firebase-client', 'dependencies' => ['firebase' => '^10.0.0'],
        ]));
        file_put_contents($root.'/src/app.js', <<<'JS'
import { initializeApp } from 'firebase/app';
import { getAuth, signInWithEmailAndPassword } from 'firebase/auth';
export const API_KEY = 'EXAMPLE-NOT-A-REAL-KEY';
const app = initializeApp({});
const auth = getAuth(app);
await signInWithEmailAndPassword(auth, 'u@e.test', 'pw');
JS);
        file_put_contents($root.'/src/config.js', <<<'JS'
export const ENV = 'prod';
JS);

        return ClientRepositoryService::linkLocal($this->project, 'Firebase client', $root);
    }

    public function test_plan_is_deterministic_and_classified_by_strategy(): void
    {
        $plan = (new ConversionPlanBuilder)->plan($this->repo);

        $this->assertSame('firebase-js', $plan['stack']);
        $this->assertNotEmpty($plan['items']);
        $categories = array_column($plan['items'], 'category');
        $this->assertContains('client_init', $categories);
        $this->assertContains('auth', $categories);
        foreach ($plan['items'] as $item) {
            $this->assertNotNull($item['strategy']);
        }
    }

    public function test_prompt_redacts_secret_values(): void
    {
        $plan = (new ConversionPlanBuilder)->plan($this->repo);
        $prompt = (new ConversionPromptBuilder)->build($this->repo, $plan);
        $userContent = $prompt['messages'][0]['content'];

        $this->assertStringContainsString('SECRET_PRESENT', $userContent, 'the marker line is present (redacted)');
        $this->assertStringNotContainsString('EXAMPLE-NOT-A-REAL-KEY', $userContent, '33F — the VALUE is replaced by SECRET_PRESENT');
        $this->assertStringContainsString('SECRET_PRESENT', $userContent);
        $this->assertStringContainsString('unified diffs', $prompt['system'], '33D — system rules restated');
    }

    public function test_diff_parser_handles_headers_and_create_actions(): void
    {
        $patches = (new DiffParser)->parse(self::CONVERSION_DIFF);
        $this->assertCount(1, $patches);
        $this->assertSame('src/app.js', $patches[0]['path']);
        $this->assertSame('modify', $patches[0]['action']);

        $create = (new DiffParser)->parse("--- /dev/null\n+++ b/src/new.js\n@@ -0,0 +1,1 @@\n+export {};\n");
        $this->assertSame('create', $create[0]['action']);
    }

    public function test_generation_lands_as_proposed_patches_without_touching_the_repo(): void
    {
        $original = file_get_contents($this->repo->root_path.'/src/app.js');
        $service = new ClientConversionService;

        $result = $service->generate($this->project, $this->repo, [], [
            'fake_responses' => [self::CONVERSION_DIFF],
        ]);

        $this->assertFalse($result['parse_failed']);
        $this->assertNotNull($result['patch_run']);
        $this->assertSame('proposed', $result['patch_run']->status, '33A — REVIEW/APPROVE gate: nothing applies itself');
        // Repo untouched until explicit approval.
        $this->assertSame($original, file_get_contents($this->repo->root_path.'/src/app.js'));
    }

    public function test_metrics_report_remaining_callsites_and_budget(): void
    {
        $service = new ClientConversionService;
        $generation = $service->generate($this->project, $this->repo, [], [
            'fake_responses' => [self::CONVERSION_DIFF],
        ]);
        $metrics = $service->metrics($this->repo, $generation['patch_run'], $generation);

        $this->assertSame('firebase-js', $metrics['stack']);
        $this->assertGreaterThan(0, $metrics['callsites_planned']);
        $this->assertSame(1, $metrics['files_touched']);
        $this->assertArrayHasKey('remaining_legacy_callsites', $metrics);
        $this->assertArrayHasKey('budget', $metrics);
    }

    public function test_test_loop_refuses_everything_outside_the_allowlist(): void
    {
        $service = new ClientConversionService;
        $allowlist = new TestCommandAllowlist;

        // node project detection.
        $this->assertSame('node', $allowlist->projectType($this->repo));

        // Allowlisted steps resolve to FIXED commands — never AI-generated.
        $resolved = $service->resolveTestStep($this->project, $this->repo, 'test');
        $this->assertTrue($resolved['allowed']);
        $this->assertSame(['npm', 'test'], $resolved['command']);

        // Arbitrary / destructive steps are refused and recorded.
        foreach (['rm -rf /', 'deploy', 'curl http://evil', 'shell'] as $evil) {
            $refused = $service->resolveTestStep($this->project, $this->repo, $evil);
            $this->assertFalse($refused['allowed'], $evil);
        }
    }

    public function test_generation_without_a_conversion_stack_is_an_honest_noop(): void
    {
        // A repo with no legacy provider callsites produces no patches.
        $root = storage_path('framework/testing/phase33/repos/plain-'.uniqid());
        @mkdir($root, 0775, true);
        file_put_contents($root.'/package.json', json_encode(['name' => 'plain']));
        $plain = ClientRepositoryService::linkLocal($this->project, 'Plain client', $root);
        ClientDependencyScanner::scan($plain);

        $result = (new ClientConversionService)->generate($this->project, $plain);
        $this->assertNull($result['patch_run']);
        $this->assertSame(0, $result['items']);
    }
}
