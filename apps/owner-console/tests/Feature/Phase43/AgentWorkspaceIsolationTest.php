<?php

namespace Tests\Feature\Phase43;

use App\Models\AgentTask;
use App\Services\Agent\AgentTaskService;
use App\Services\Agent\AgentWorkspaceService;
use App\Services\Agent\Contract\AgentRuntimeException;
use Tests\Feature\Phase43\Concerns\BuildsAgentFixtureProject;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AgentWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase, BuildsAgentFixtureProject;

    public function test_every_task_gets_a_unique_worktree_inside_the_workspace_root(): void
    {
        $this->fastAgentConfig();
        [$owner] = [$this->agentOwner()];
        [$project, $repoPath] = $this->agentFixtureProject($owner);

        $service = app(AgentWorkspaceService::class);
        $taskA = AgentTask::create([
            'code' => 'AGT-9001', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'status' => 'queued',
        ]);
        $taskB = AgentTask::create([
            'code' => 'AGT-9002', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'status' => 'queued',
        ]);

        $wsA = $service->createForTask($taskA, $project);
        $wsB = $service->createForTask($taskB, $project);

        $this->assertNotSame($wsA->path, $wsB->path);
        $this->assertStringStartsWith(AgentWorkspaceService::root(), $wsA->path);
        $this->assertStringStartsWith(AgentWorkspaceService::root(), $wsB->path);
        $this->assertFileExists($wsA->path.'/src/greeting.php');

        // The authoritative repo is untouched: no extra working-tree files.
        $status = trim(\Illuminate\Support\Facades\Process::timeout(30)->run(['git', '-C', $repoPath, 'status', '--porcelain'])->output());
        $this->assertSame('', $status);

        // Authoritative HEAD matches the recorded base revision.
        $head = trim(\Illuminate\Support\Facades\Process::timeout(30)->run(['git', '-C', $repoPath, 'rev-parse', 'HEAD'])->output());
        $this->assertSame($head, $wsA->base_revision);

        AgentWorkspaceService::deleteTree($wsA->path);
        AgentWorkspaceService::deleteTree($wsB->path);
        $this->cleanupFixtureRepo($repoPath);
    }

    public function test_relative_path_guards_reject_traversal_absolute_git_and_symlink_escapes(): void
    {
        $workspace = sys_get_temp_dir().'/agent-guard-'.\Illuminate\Support\Str::random(6);
        @mkdir($workspace, 0777, true);

        try {
            // Traversal.
            try {
                AgentWorkspaceService::validateRelativePath('../outside.php', $workspace);
                $this->fail('Traversal was not rejected.');
            } catch (AgentRuntimeException $e) {
                $this->assertSame(AgentRuntimeException::WORKSPACE_FAILED, $e->category);
            }

            // Absolute (unix + windows).
            try {
                AgentWorkspaceService::validateRelativePath('/etc/passwd', $workspace);
                $this->fail('Absolute path was not rejected.');
            } catch (AgentRuntimeException) {
            }
            try {
                AgentWorkspaceService::validateRelativePath('C:/Windows/system32/config', $workspace);
                $this->fail('Windows absolute path was not rejected.');
            } catch (AgentRuntimeException) {
            }

            // VCS metadata.
            try {
                AgentWorkspaceService::validateRelativePath('.git/config', $workspace);
                $this->fail('.git path was not rejected.');
            } catch (AgentRuntimeException) {
            }

            // Null bytes.
            try {
                AgentWorkspaceService::validateRelativePath("safe\0.php", $workspace);
                $this->fail('Null byte path was not rejected.');
            } catch (AgentRuntimeException) {
            }

            // Symlink escape: a symlinked directory pointing OUTSIDE must be refused.
            // (On Windows without symlink privileges, creation fails and the
            // POSIX-specific check is skipped there; it holds on Linux/CI.)
            $outside = sys_get_temp_dir().'/agent-outside-'.\Illuminate\Support\Str::random(6);
            @mkdir($outside, 0777, true);
            @mkdir($workspace.'/linked', 0777, true);

            if (@symlink($outside, $workspace.'/linked/escape')) {
                try {
                    AgentWorkspaceService::validateRelativePath('linked/escape/evil.php', $workspace);
                    $this->fail('Symlink escape was not rejected.');
                } catch (AgentRuntimeException $e) {
                    $this->assertSame(AgentRuntimeException::WORKSPACE_FAILED, $e->category);
                }
            }
            @rmdir($outside);

            // Safe path passes.
            $clean = AgentWorkspaceService::validateRelativePath('src/greeting.php', $workspace);
            $this->assertSame('src/greeting.php', $clean);
        } finally {
            AgentWorkspaceService::deleteTree($workspace);
        }
    }

    public function test_delete_tree_refuses_paths_outside_the_workspace_root(): void
    {
        $outside = sys_get_temp_dir().'/agent-nuketest-'.\Illuminate\Support\Str::random(6);
        @mkdir($outside, 0777, true);
        file_put_contents($outside.'/precious.txt', 'keep me');

        AgentWorkspaceService::deleteTree($outside);

        $this->assertFileExists($outside.'/precious.txt');
        @unlink($outside.'/precious.txt');
        @rmdir($outside);
    }

    public function test_a_non_git_project_is_refused(): void
    {
        $this->fastAgentConfig();
        $owner = $this->agentOwner();

        $repoPath = sys_get_temp_dir().'/agent-nogit-'.\Illuminate\Support\Str::random(6);
        @mkdir($repoPath, 0777, true);
        file_put_contents($repoPath.'/a.txt', 'x');

        [$project] = $this->agentFixtureProject($owner);
        $project->clientRepositories()->update(['root_path' => $repoPath]);

        $task = AgentTask::create([
            'code' => 'AGT-9010', 'created_by' => $owner->id, 'project_id' => $project->id,
            'prompt' => 'x', 'status' => 'queued',
        ]);

        $this->expectException(AgentRuntimeException::class);
        try {
            app(AgentWorkspaceService::class)->createForTask($task, $project);
        } finally {
            $this->cleanupFixtureRepo($repoPath);
        }
    }
}
