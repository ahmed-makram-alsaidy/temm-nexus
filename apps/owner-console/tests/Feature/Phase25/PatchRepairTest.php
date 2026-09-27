<?php

namespace Tests\Feature\Phase25;

use App\Models\AiPatchRun;
use App\Models\AiRepairLoop;
use App\Models\ClientRepository;
use App\Services\ControlPlane\Ai\PatchWorkspace;
use App\Services\ControlPlane\Ai\RepairLoopService;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase25\Concerns\BuildsPhase25Fixture;
use Tests\TestCase;

/** Phase 25I/J — patch workspace, path guard, review/approve/apply, test/repair loop. */
class PatchRepairTest extends TestCase
{
    use RefreshDatabase;
    use BuildsPhase25Fixture;

    protected string $repoRoot;
    protected ClientRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPhase25();
        $this->repoRoot = $this->buildFixtureRepo();
        $this->repo = ClientRepositoryService::linkLocal($this->projectA, 'fixture repo', $this->repoRoot);
    }

    protected function patchRun(array $patches): AiPatchRun
    {
        return PatchWorkspace::create($this->projectA, null, $this->repo, $patches, $this->repoRoot);
    }

    // ── 25I.2 path guard ────────────────────────────────────────────────

    public function test_patch_path_guard_rejects_traversal_and_system_paths(): void
    {
        foreach (['../outside.php', '/etc/cron.d/evil', 'C:/Windows/evil.php', '..\\..\\x.php'] as $evil) {
            try {
                $this->patchRun([['path' => $evil, 'action' => 'create', 'content' => 'x']]);
                $this->fail("path accepted: {$evil}");
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        // No patch run row was created by refused patches.
        $this->assertSame(0, AiPatchRun::count());
    }

    // ── 25I.3/25I.4 review → approve → apply ────────────────────────────

    public function test_apply_requires_approval(): void
    {
        $run = $this->patchRun([['path' => 'src/generated/PlatformClient.js', 'action' => 'create', 'content' => 'export const x = 1;']]);

        $this->expectException(HttpException::class);
        PatchWorkspace::apply($run, $this->admin);
    }

    public function test_create_patch_applies_after_approval_and_preserves_unrelated_files(): void
    {
        $before = file_get_contents($this->repoRoot.'/supabaseClient.js');
        $run = $this->patchRun([[
            'path' => 'src/generated/PlatformClient.js', 'action' => 'create',
            'reason' => 'SDK swap', 'risk' => 'low', 'tests' => 'npm test',
            'content' => "import { BackendClient } from '@platform/backend-sdk';\nexport const backend = new BackendClient({});\n",
        ]]);
        $this->assertSame('proposed', $run->status);

        PatchWorkspace::approve($run, $this->admin);
        $this->assertSame('approved', $run->fresh()->status);

        $applied = PatchWorkspace::apply($run->fresh(), $this->admin);
        $this->assertSame('applied', $applied->status);
        $this->assertFileExists($this->repoRoot.'/src/generated/PlatformClient.js');

        // Unrelated file untouched.
        $this->assertSame($before, file_get_contents($this->repoRoot.'/supabaseClient.js'));
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'PATCH_APPLIED', 'project_id' => $this->projectA->id]);
    }

    public function test_create_refuses_to_clobber_existing_file(): void
    {
        $run = $this->patchRun([['path' => 'supabaseClient.js', 'action' => 'create', 'content' => 'EVIL OVERWRITE']]);
        PatchWorkspace::approve($run, $this->admin);

        // Apply reports the refusal honestly (failed run) and never overwrites.
        $applied = PatchWorkspace::apply($run->fresh(), $this->admin);
        $this->assertSame('failed', $applied->status);
        $this->assertStringContainsString('createClient', (string) file_get_contents($this->repoRoot.'/supabaseClient.js'), 'original content preserved');
    }

    public function test_modify_patch_applies_unified_diff(): void
    {
        // Current: "export async function orders() { return supabase.from('orders').select('*'); }"
        $diff = <<<'DIFF'
--- a/supabaseClient.js
+++ b/supabaseClient.js
@@ -7,3 +7,4 @@
 export async function orders() {
-  return supabase.from('orders').select('*');
+  // Mapped to platform API (callsite manifest #1)
+  return backend.http.get('/api/v1/orders');
 }
DIFF;

        $run = $this->patchRun([['path' => 'supabaseClient.js', 'action' => 'modify', 'diff' => $diff, 'reason' => 'swap', 'risk' => 'medium']]);
        PatchWorkspace::approve($run, $this->admin);
        $applied = PatchWorkspace::apply($run->fresh(), $this->admin);
        $this->assertSame('applied', $applied->status, 'apply failed: '.collect($run->fresh()->files()->get())->map(fn ($f) => $f->reason)->implode(' | '));

        $content = (string) file_get_contents($this->repoRoot.'/supabaseClient.js');
        $this->assertStringContainsString("/api/v1/orders", $content);
        $this->assertStringContainsString("export async function notify", $content, 'unrelated functions preserved');
    }

    public function test_diff_that_does_not_apply_is_reported_honestly(): void
    {
        $diff = <<<'DIFF'
--- a/supabaseClient.js
+++ b/supabaseClient.js
@@ -999,6 +999,7 @@
-some line that does not exist
+replacement
DIFF;

        $run = $this->patchRun([['path' => 'supabaseClient.js', 'action' => 'modify', 'diff' => $diff]]);
        PatchWorkspace::approve($run, $this->admin);
        $applied = PatchWorkspace::apply($run->fresh(), $this->admin);
        $this->assertSame('failed', $applied->status);
        $this->assertStringContainsString('supabase.from(\'orders\')', (string) file_get_contents($this->repoRoot.'/supabaseClient.js'), 'original intact on failed apply');
    }

    public function test_reject_flow(): void
    {
        $run = $this->patchRun([['path' => 'new.php', 'action' => 'create', 'content' => '<?php']]);
        PatchWorkspace::reject($run, $this->admin, 'bad idea');

        $this->assertSame('rejected', $run->fresh()->status);
        $this->assertFileDoesNotExist($this->repoRoot.'/new.php');
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'PATCH_REJECTED', 'project_id' => $this->projectA->id]);
    }

    // ── 25J test/repair loop ────────────────────────────────────────────

    public function test_command_allowlist(): void
    {
        $this->expectException(HttpException::class);
        RepairLoopService::start($this->projectA, null, 'rm -rf /');
    }

    public function test_loop_passes_on_green_command(): void
    {
        // php -l on a directory is not a test; use the phpunit template in a
        // tiny fixture project with a single passing test.
        $tree = storage_path('framework/testing/phase25/testtree-'.uniqid());
        @mkdir($tree.'/tests', 0775, true);
        @mkdir($tree.'/vendor/bin', 0775, true);
        // Stub phpunit binary that exits 0 (the loop runs allowlisted commands
        // as-is; the fixture proves the loop mechanics, not phpunit itself).
        file_put_contents($tree.'/vendor/bin/phpunit', "<?php echo \"OK (3 tests, 5 assertions)\\n\"; exit(0);\n");
        chmod($tree.'/vendor/bin/phpunit', 0777);

        $loop = RepairLoopService::start($this->projectA, null, 'phpunit');
        $result = RepairLoopService::runTests($loop, $tree);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('passed', $loop->fresh()->status);
    }

    public function test_loop_captures_failures_and_respects_limits(): void
    {
        $tree = storage_path('framework/testing/phase25/testtree-'.uniqid());
        @mkdir($tree.'/vendor/bin', 0775, true);
        file_put_contents($tree.'/vendor/bin/phpunit', "<?php echo \"1) Tests\\\\Foo::test_bar\\n\"; echo \"PASSWORD=super-secret-99\\n\"; echo \"Tests: 4, Assertions: 9\\n\"; exit(1);\n");
        chmod($tree.'/vendor/bin/phpunit', 0777);

        $loop = RepairLoopService::start($this->projectA, null, 'phpunit', ['max_iterations' => 2]);
        $result = RepairLoopService::runTests($loop, $tree);

        $this->assertSame(1, $result['exit_code']);
        $this->assertNotEmpty($result['failures']);
        $this->assertSame('running', $loop->fresh()->status, 'limit not reached yet');

        RepairLoopService::runTests($loop, $tree);
        $this->assertSame('limit_reached', $loop->fresh()->status);
        $this->assertSame(2, $loop->fresh()->iterations);

        // Failures are redacted before storage/return.
        $encoded = json_encode($loop->fresh()->history);
        $this->assertStringNotContainsString('super-secret-99', $encoded);
    }

    public function test_ai_budget_is_enforced(): void
    {
        $loop = RepairLoopService::start($this->projectA, null, 'phpunit', ['max_ai_calls' => 1]);
        $loop->ai_calls = 1;
        $loop->save();

        $this->expectException(HttpException::class);
        RepairLoopService::proposeRepair($loop, new \App\Services\ControlPlane\Ai\MigrationCopilot, ['failures' => []]);
    }
}
