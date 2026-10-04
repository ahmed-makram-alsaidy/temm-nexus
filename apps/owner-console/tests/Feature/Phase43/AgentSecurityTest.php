<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentApproval;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\AdminAuditEntry;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Agent\AgentApplyService;
use App\Services\Agent\AgentApprovalService;
use App\Services\Agent\AgentChangesetService;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\Feature\Phase43\Concerns\RunsMockOpenCode;
use Tests\TestCase;

/**
 * Mandatory security suite for the agent runtime platform: endpoint SSRF,
 * approval replay/tamper, stale source, cross-project isolation, oversized
 * changesets, and secret-leakage scans.
 */
class AgentSecurityTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject, RunsMockOpenCode;

    protected function tearDown(): void
    {
        $this->tearDownMock();
        parent::tearDown();
    }

    protected function mockRuntime(string $baseUrl, ?string $secret = 'mock-password'): AgentRuntime
    {
        return AgentRuntime::create([
            'driver' => 'opencode', 'display_name' => 'Sec Runtime',
            'mode' => AgentRuntime::MODE_EXTERNAL, 'endpoint' => $baseUrl,
            'auth_secret_encrypted' => $secret, 'enabled' => true,
            'status' => AgentRuntime::STATUS_CONNECTED, 'version' => '1.18.34',
            'timeout_seconds' => 60, 'max_concurrent_tasks' => 1, 'workspace_retention_days' => 1,
        ]);
    }

    // ── SSRF ────────────────────────────────────────────────────────────

    public function test_external_endpoints_must_be_https_and_non_private(): void
    {
        config(['agent.allow_loopback_endpoints' => false]);

        foreach ([
            'http://example.com/opencode',
            'http://127.0.0.1:4096',
            'http://10.0.0.5:4096',
            'http://192.168.1.10:4096',
            'http://metadata.google.internal',
        ] as $bad) {
            $runtime = $this->mockRuntime($bad);
            $connection = app(\App\Services\Agent\AgentRuntimeManager::class)->forRuntime($runtime)->testConnection($runtime);

            $this->assertFalse($connection->ok, "Endpoint {$bad} was accepted.");
            $this->assertSame(AgentRuntimeException::INVALID_RUNTIME_RESPONSE, $connection->errorCategory, "Endpoint {$bad} failed for the wrong reason.");
        }
    }

    public function test_loopback_is_only_allowed_with_the_dev_flag(): void
    {
        config(['agent.allow_loopback_endpoints' => false]);
        $runtime = $this->mockRuntime('http://127.0.0.1:4096');
        $conn = app(\App\Services\Agent\AgentRuntimeManager::class)->forRuntime($runtime)->testConnection($runtime);
        $this->assertFalse($conn->ok);

        config(['agent.allow_loopback_endpoints' => true]);
        $conn2 = app(\App\Services\Agent\AgentRuntimeManager::class)->forRuntime($runtime)->testConnection($runtime);
        $this->assertSame(AgentRuntimeException::RUNTIME_UNAVAILABLE, $conn2->errorCategory); // past SSRF (nothing listens), refused for availability
    }

    // ── Approval replay / tamper / staleness ────────────────────────────

    protected function approvedTask(array &$refs = []): array
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);
        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Update greeting.');

        $task->refresh();
        $changeset = AgentChangeset::where('agent_task_id', $task->id)->firstOrFail();
        app(AgentTaskService::class)->approve($task, $owner);
        $approval = AgentApproval::where('agent_task_id', $task->id)->where('status', 'approved')->firstOrFail();

        $refs = [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval, $baseUrl];

        return $refs;
    }

    public function test_an_approval_cannot_be_replayed_for_a_second_apply(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval] = $refs;

        $service = app(AgentTaskService::class);
        $service->apply($task, $owner); // first apply consumes the approval
        $this->assertNotNull($approval->refresh()->consumed_at);

        // Force the task back to awaiting_approval and attempt a replay.
        $task->update(['status' => AgentTask::STATUS_AWAITING_APPROVAL]);

        $this->expectException(AgentRuntimeException::class);
        $this->expectExceptionMessage('replay');
        app(AgentApplyService::class)->apply($task, $owner);
    }

    public function test_tampering_with_the_changeset_after_approval_is_refused(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval] = $refs;

        // TAMPERED: mutate the stored diff after approval (fingerprint now mismatches).
        $changeset->update(['diff' => $changeset->diff."\n-- malicious\n+rm -rf /"]);

        $this->expectException(AgentRuntimeException::class);
        $this->expectExceptionMessage('tamper');
        app(AgentApprovalService::class)->revalidateAndConsume(
            $task->refresh(),
            $approval->refresh(),
            $changeset->refresh()
        );
    }

    public function test_moving_source_since_the_plan_is_refused_as_stale(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval] = $refs;

        // STALE: authoritative source moved on after the workspace was cut.
        file_put_contents($repoPath.'/NEWFILE.md', "moved on\n");
        Process::timeout(30)->run(['git', '-C', $repoPath, 'add', '-A']);
        Process::timeout(30)->run(['git', '-C', $repoPath, 'commit', '-m', 'upstream moved']);

        $this->expectException(AgentRuntimeException::class);
        $this->expectExceptionMessage('stale');
        app(AgentApprovalService::class)->revalidateAndConsume($task->refresh(), $approval->refresh(), $changeset->refresh());
    }

    public function test_oversized_changesets_cannot_be_applied_automatically(): void
    {
        $this->fastAgentConfig();
        config(['agent.limits.max_diff_bytes' => 100]);
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);
        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, null, 'Big changes.');

        $task->refresh();
        $changeset = AgentChangeset::where('agent_task_id', $task->id)->firstOrFail();
        $this->assertTrue($changeset->truncated);

        try {
            app(AgentTaskService::class)->approve($task, $owner);
            $this->fail('Approving a truncated changeset did not refuse.');
        } catch (AgentRuntimeException $e) {
            $this->assertStringContainsString('manual review', $e->getMessage());
        }

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    // ── Cross-project isolation ─────────────────────────────────────────

    public function test_users_without_project_access_cannot_act_on_its_tasks(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval] = $refs;

        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-'.\Illuminate\Support\Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => null,
        ]);

        // authorize() aborts (403) for users without the capability.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(AgentTaskService::class)->approve($task->refresh(), $outsider);
    }

    public function test_workbench_hides_tasks_from_projects_the_user_cannot_reach(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task] = $refs;

        $outsider = User::create([
            'name' => 'Outsider 2',
            'email' => 'outsider2-'.\Illuminate\Support\Str::random(5).'@example.test',
            'password' => bcrypt('a-very-long-password-1!'),
            'platform_role' => null,
        ]);

        $this->be($outsider);
        $page = new \App\Filament\Pages\DeveloperAgent;
        $page->task = $task->id;

        $this->assertNull($page->currentTask());
    }

    // ── Secret leakage ──────────────────────────────────────────────────

    public function test_no_secret_or_private_content_appears_anywhere_in_the_product(): void
    {
        $refs = [];
        $this->approvedTask($refs);
        [$owner, $project, $repoPath, $runtime, $task, $changeset, $approval] = $refs;

        $task->refresh();

        // The runtime auth secret never appears in events, HTML, or audit.
        $eventPayloads = json_encode($task->events()->get()->pluck('payload'));
        $this->assertStringNotContainsString('mock-password', $eventPayloads ?? '');

        // The mock's hidden reasoning and command stdout never surface.
        $this->assertStringNotContainsString('SECRET-REASONING-CONTENT', $eventPayloads ?? '');
        $this->assertStringNotContainsString('OUTPUT-CONTENTS-ARE-NOT-STORED', $eventPayloads ?? '');

        // The stored credential is encrypted at rest (raw ciphertext check).
        $raw = \Illuminate\Support\Facades\DB::table('agent_runtimes')->where('id', $runtime->id)->value('auth_secret_encrypted');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('mock-password', (string) $raw);

        // Audit metadata carries no secrets.
        $auditMetadata = json_encode(AdminAuditEntry::query()->where('target_id', $task->id)->pluck('metadata'));
        $this->assertStringNotContainsString('mock-password', $auditMetadata ?? '');

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }
}
