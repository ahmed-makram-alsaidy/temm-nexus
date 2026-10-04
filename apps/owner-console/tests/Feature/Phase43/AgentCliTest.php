<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentChangeset;
use App\Models\AgentRuntime;
use App\Models\AgentTask;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\Feature\Phase43\Concerns\RunsMockOpenCode;
use Tests\TestCase;

/**
 * CLI acceptance: the agent:* commands drive the SAME service layer as the
 * web UI and share the exact persisted task/session/changeset state.
 */
class AgentCliTest extends TestCase
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
            'driver' => 'opencode', 'display_name' => 'CLI Runtime',
            'mode' => AgentRuntime::MODE_EXTERNAL, 'endpoint' => $baseUrl,
            'auth_secret_encrypted' => 'mock-password', 'enabled' => true,
            'status' => AgentRuntime::STATUS_CONNECTED, 'version' => '1.18.34',
            'timeout_seconds' => 120, 'max_concurrent_tasks' => 2, 'workspace_retention_days' => 1,
        ]);
    }

    public function test_runtimes_and_models_commands(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');

        $runtime = $this->mockRuntime($baseUrl);

        $this->artisan('agent:runtimes')->expectsOutputToContain('CLI Runtime')->assertSuccessful();
        $this->artisan('agent:models')->expectsOutputToContain('mockprovider/mock-small')->assertSuccessful();
        $this->artisan('agent:models', ['--filter' => 'mock-large'])->expectsOutputToContain('mockprovider/mock-large')->assertSuccessful();
        $this->artisan('agent:status')->assertSuccessful();

        $this->stopMockOpenCode();
    }

    public function test_full_cli_flow_run_show_diff_approve_apply(): void
    {
        $this->fastAgentConfig();
        $baseUrl = $this->startMockOpenCode('happy');
        $owner = $this->agentOwner();

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        config(['agent.verification.projects' => [$project->slug => ['"'.PHP_BINARY.'" -r "exit(0);"']]]);

        $runtime = $this->mockRuntime($baseUrl);

        // agent:run (queue sync runs the task inline).
        $this->artisan('agent:run', [
            '--project' => $project->slug,
            '--runtime' => 'CLI Runtime',
            '--model' => 'mockprovider/mock-small',
            '--user' => $owner->email,
            'prompt' => 'Update the greeting to HELLO TEMM.',
        ])->expectsOutputToContain('Task created: AGT-0001')->assertSuccessful();

        $task = AgentTask::where('code', 'AGT-0001')->firstOrFail();
        $task->refresh();
        $this->assertSame(AgentTask::STATUS_AWAITING_APPROVAL, $task->status);

        // agent:show references the SAME persisted task.
        $this->artisan('agent:show', ['code' => 'AGT-0001'])
            ->expectsOutputToContain('awaiting_approval')
            ->assertSuccessful();

        // agent:diff shows the deterministic changeset.
        $this->artisan('agent:diff', ['code' => 'AGT-0001'])
            ->expectsOutputToContain('diff --git a/src/greeting.php')
            ->assertSuccessful();

        // agent:approve with the operator identity.
        $this->artisan('agent:approve', ['code' => 'AGT-0001', '--user' => $owner->email])
            ->expectsOutputToContain('approved')
            ->assertSuccessful();

        // agent:apply applies + verifies.
        $this->artisan('agent:apply', ['code' => 'AGT-0001', '--user' => $owner->email])
            ->expectsOutputToContain('Verification: passed')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_COMPLETED, $task->status);
        $this->assertStringContainsString('HELLO TEMM', file_get_contents($repoPath.'/src/greeting.php'));

        // CLI and browser share the persisted state — the web page shows it.
        $this->actingAs($owner);
        $page = new \App\Filament\Pages\DeveloperAgent;
        $page->task = $task->id;
        $this->assertNotNull($page->currentTask());
        $this->assertSame($task->id, $page->currentTask()->id);

        $this->stopMockOpenCode();
        AgentWorkspaceService::deleteTree($task->workspaceRecord->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_cli_cancel_requires_a_user_and_uses_service_authorization(): void
    {
        $this->fastAgentConfig();
        $owner = $this->agentOwner();

        [$project, $repoPath] = $this->agentFixtureProject($owner);
        $runtime = AgentRuntime::create([
            'driver' => 'opencode', 'display_name' => 'C Runtime', 'mode' => 'managed', 'enabled' => true, 'status' => 'untested',
        ]);

        $task = AgentTask::create([
            'code' => 'AGT-0002', 'created_by' => $owner->id, 'project_id' => $project->id,
            'agent_runtime_id' => $runtime->id, 'prompt' => 'x', 'status' => 'queued',
        ]);

        // Missing --user is refused.
        $this->artisan('agent:cancel', ['code' => 'AGT-0002'])->assertFailed();

        $this->artisan('agent:cancel', ['code' => 'AGT-0002', '--user' => $owner->email])
            ->expectsOutputToContain('cancelled')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame(AgentTask::STATUS_CANCELLED, $task->status);

        $this->cleanupFixtureRepo($repoPath);
    }
}
