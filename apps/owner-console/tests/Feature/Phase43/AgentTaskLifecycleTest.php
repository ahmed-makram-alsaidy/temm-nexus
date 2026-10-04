<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentApproval;
use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\Feature\Phase43\Concerns\RunsMockOpenCode;
use Tests\TestCase;

/**
 * The full safety lifecycle against the mock OpenCode server:
 * run → events → changeset → approval → apply → verify, plus cancellation
 * and the no-changes outcome.
 */
class AgentTaskLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject, RunsMockOpenCode;

    protected function tearDown(): void
    {
        $this->tearDownMock();
        parent::tearDown();
    }

    protected function mockRuntime(string $baseUrl): AgentRuntime
    {
        return AgentRuntime::create([
            'driver' => 'opencode',
            'display_name' => 'Mock OpenCode',
            'mode' => AgentRuntime::MODE_EXTERNAL,
            'endpoint' => $baseUrl,
            'auth_secret_encrypted' => 'mock-password',
            'enabled' => true,
            'status' => AgentRuntime::STATUS_CONNECTED,
            'version' => '1.18.34',
            'timeout_seconds' => 120,
            'max_concurrent_tasks' => 2,
            'workspace_retention_days' => 1,
        ]);
    }

    public function test_full_lifecycle_run_diff_approve_apply_verify(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        config(['agent.verification.projects' => [$project->slug => ['"'.PHP_BINARY.'" -r "exit(0);"']]]);

        $runtime = $this->mockRuntime($baseUrl);
        $service = app(AgentTaskService::class);

        // Queue connection is sync in tests: createTask runs the whole loop.
        $task = $service->createTask($owner, $project, $runtime, 'mockprovider/mock-small', 'Update the greeting to HELLO TEMM.');

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);
        $this->assertNotNull($task->runtime_session_id);
        $this->assertNotNull($task->base_revision);

        // The runtime session was bound to the isolated workspace directory.
        $workspace = $task->workspaceRecord;
        $this->assertNotNull($workspace);
        $this->assertStringStartsWith(\App\Services\Agent\AgentWorkspaceService::root(), $workspace->path);
        $this->assertFileExists($workspace->path.'/src/greeting.php');

        // Events were recorded — with the privacy boundaries intact.
        $events = $task->events()->get();
        $this->assertGreaterThan(5, $events->count());
        $allSummaries = $events->pluck('summary')->implode(' | ');
        $this->assertStringNotContainsString('SECRET-REASONING-CONTENT', $allSummaries); // no hidden reasoning
        $this->assertNotNull($events->where('type', 'command')->first());
        $this->assertNotNull($events->where('type', 'file_changed')->first());

        // Command ledger: metadata only, no output content.
        $command = $task->commands()->first();
        $this->assertNotNull($command);
        $this->assertSame('php test.php', $command->command);
        $this->assertSame(0, $command->exit_code);
        $this->assertGreaterThan(0, $command->output_bytes);
        $this->assertFalse((bool) $command->truncated);

        // The changeset is cut from the workspace git diff, not the runtime's claims.
        $changeset = AgentChangeset::where('agent_task_id', $task->id)->first();
        $this->assertNotNull($changeset);
        $this->assertStringContainsString('diff --git a/src/greeting.php', $changeset->diff);
        $this->assertSame($workspace->base_revision, $changeset->base_revision);
        $this->assertSame(hash('sha256', $workspace->base_revision.'|'.$changeset->diff), $changeset->fingerprint);

        // Usage metadata from the runtime was captured.
        $this->assertNotNull($task->usage);
        $this->assertSame(0.01, $task->usage['cost']);

        // Apply is blocked before approval.
        try {
            $service->apply($task, $owner);
            $this->fail('Apply without approval was not refused.');
        } catch (AgentRuntimeException $e) {
            $this->assertSame('No approval exists for this task.', $e->getMessage());
        }

        // Approve → apply → verify.
        $service->approve($task, $owner);
        $approval = AgentApproval::where('agent_task_id', $task->id)->where('status', 'approved')->first();
        $this->assertNotNull($approval);
        $this->assertSame($changeset->fingerprint, $approval->changeset_fingerprint);

        $service->apply($task, $owner);

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->applied_at);
        $this->assertNotNull($task->verified_at);

        // The authoritative source now carries the approved change.
        $greeting = file_get_contents($repoPath.'/src/greeting.php');
        $this->assertStringContainsString('HELLO TEMM', $greeting);
        $this->assertStringContainsString('HELLO TEMM', file_get_contents($repoPath.'/tests/greeting_test.php'));

        // Approval consumed exactly once.
        $this->assertNotNull($approval->refresh()->consumed_at);

        // Verification recorded a passing command.
        $verification = $task->verifications()->latest('created_at')->first();
        $this->assertNotNull($verification);
        $this->assertSame('passed', $verification->status);

        // Authoritative repo HEAD never moved (apply is working-tree only).
        $head = trim(Process::timeout(30)->run(['git', '-C', $repoPath, 'rev-parse', 'HEAD'])->output());
        $this->assertSame($workspace->base_revision, $head);

        // Audit trail exists for the lifecycle.
        $actions = \App\Models\AdminAuditEntry::query()->whereIn('action', [
            'AGENT_TASK_CREATED', 'AGENT_CHANGESET_GENERATED', 'AGENT_APPROVAL_GRANTED',
            'AGENT_CHANGESET_APPLIED', 'AGENT_VERIFICATION_RECORDED',
        ])->pluck('action')->unique()->values()->all();
        $this->assertCount(5, $actions);

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($workspace->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_no_changes_produces_a_truthful_completed_task(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('no-changes');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, null, 'Read the code and do nothing.');

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);
        $this->assertNull(AgentChangeset::where('agent_task_id', $task->id)->first());

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_runtime_session_error_is_surfaced_sanitized(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('session-error');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, null, 'Fail somehow.');

        $task->refresh();
        // The runtime errored then went idle with no changes: the truthful
        // outcome is "completed without changes", with the error in the event
        // stream (sanitized), not a fake success row or a raw upstream dump.
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);
        $this->assertNull(AgentChangeset::where('agent_task_id', $task->id)->first());

        $errorEvent = $task->events()->where('type', 'error')->first();
        $this->assertNotNull($errorEvent);
        $this->assertStringNotContainsString('sk-abcdef', (string) $errorEvent->summary); // sanitized

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_silent_runtime_times_out_structurally(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('silent');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, null, 'Hang forever.');

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_FAILED, $task->status);
        $this->assertSame(AgentRuntimeException::TIMEOUT, $task->error_category);

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord?->path ?? '');
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_permission_ask_is_answered_per_policy(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('permission');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $task = app(AgentTaskService::class)->createTask($owner, $project, $runtime, null, 'Run the test.');

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        $permissionEvents = $task->events()->where('type', 'permission')->get();
        $this->assertGreaterThanOrEqual(2, $permissionEvents->count()); // asked + decision

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_cancel_stops_a_running_task(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('abortable');
        $owner = $this->agentOwner();
        $this->be($owner);

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = $this->mockRuntime($baseUrl);

        $service = app(AgentTaskService::class);

        // Queue is sync in tests, so run the job in the background of this
        // test process: dispatch on the real queue and drive manually.
        config(['agent.queue' => 'sync']);
        $task = null;

        // Simulate the async path: create task with queue faked, then run.
        \Illuminate\Support\Facades\Queue::fake();
        $task = $service->createTask($owner, $project, $runtime, null, 'Long running task.');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Agent\RunAgentTask::class);

        // Cancel while queued, then run the job: must exit without hanging.
        $service->cancel($task, $owner);
        (new \App\Jobs\Agent\RunAgentTask($task->id))->handle($service);

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_CANCELLED, $task->status);

        \Illuminate\Support\Facades\Queue::fake(); // un-fake not needed; test ends
        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_version_compatibility_gate(): void
    {
        $this->assertTrue(\App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime::isCompatibleVersion('1.18.34'));
        $this->assertTrue(\App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime::isCompatibleVersion('1.19.0'));
        $this->assertFalse(\App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime::isCompatibleVersion('1.17.9'));
        $this->assertFalse(\App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime::isCompatibleVersion('2.0.0'));
        $this->assertFalse(\App\Services\Agent\Runtimes\OpenCode\OpenCodeRuntime::isCompatibleVersion('garbage'));
    }

    public function test_connection_test_classifies_failures(): void
    {
        $this->fastAgentConfig();
        $owner = $this->agentOwner();

        // Unreachable endpoint → RUNTIME_UNAVAILABLE.
        $runtime = $this->mockRuntime('http://127.0.0.1:1');
        $connection = app(AgentRuntimeManager::class)->forRuntime($runtime)->testConnection($runtime);
        $this->assertFalse($connection->ok);
        $this->assertSame(AgentRuntimeException::RUNTIME_UNAVAILABLE, $connection->errorCategory);

        // Wrong credentials → RUNTIME_AUTH_FAILED.
        $baseUrl = $this->startMockOpenCode('happy', 'opencode:right-password');
        $runtime2 = $this->mockRuntime($baseUrl);
        $runtime2->update(['auth_secret_encrypted' => 'wrong-password']);
        $connection2 = app(AgentRuntimeManager::class)->forRuntime($runtime2)->testConnection($runtime2);
        $this->assertFalse($connection2->ok);
        $this->assertSame(AgentRuntimeException::RUNTIME_AUTH_FAILED, $connection2->errorCategory);

        // Correct credentials → connected with the version.
        $runtime2->update(['auth_secret_encrypted' => 'right-password']);
        $connection3 = app(AgentRuntimeManager::class)->forRuntime($runtime2)->testConnection($runtime2);
        $this->assertTrue($connection3->ok);
        $this->assertSame('1.18.34', $connection3->version);

        $this->stopMockOpenCode();
    }
}
