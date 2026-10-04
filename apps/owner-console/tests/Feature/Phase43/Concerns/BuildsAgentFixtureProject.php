<?php

namespace Tests\Feature\Phase43\Concerns;

use App\Models\ClientRepository;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\Roles;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Builds a disposable, Git-backed project fixture for agent runtime tests:
 * README + small source file + one test, committed — the exact shape of the
 * real-acceptance scenario, at test scale.
 */
trait BuildsAgentFixtureProject
{
    protected function agentOwner(): User
    {
        $user = User::create([
            'name' => 'Agent Owner',
            'email' => 'agent-owner-'.Str::random(6).'@example.test',
            'password' => bcrypt(Str::random(24)),
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);

        return $user;
    }

    /** @return array{0: Project, 1: string} project + authoritative repo path */
    protected function agentFixtureProject(User $owner, string $slugPrefix = 'agentfix'): array
    {
        $repoPath = sys_get_temp_dir().'/agent-fixture-'.Str::random(10);
        @mkdir($repoPath.'/src', 0777, true);
        @mkdir($repoPath.'/tests', 0777, true);

        file_put_contents($repoPath.'/README.md', "# Fixture\n");
        file_put_contents($repoPath.'/src/greeting.php', "<?php\necho 'HELLO';\n");
        file_put_contents($repoPath.'/tests/greeting_test.php', "<?php\nassert('HELLO' === 'HELLO');\n");

        $git = fn (array $args) => Process::timeout(30)->run(array_merge(['git', '-C', $repoPath], $args));
        $git(['init', '--initial-branch=main']);
        $git(['config', 'user.email', 'fixture@example.test']);
        $git(['config', 'user.name', 'Fixture']);
        $git(['add', '-A']);
        $git(['commit', '-m', 'fixture']);

        $workspace = Workspace::create([
            'name' => 'Agent WS '.Str::random(4),
            'slug' => 'agent-ws-'.Str::random(6),
            'kind' => 'client',
            'status' => 'active',
        ]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'name' => 'Agent Fixture',
            'slug' => $slugPrefix.'-'.Str::random(6),
            'status' => 'active',
            'environment' => 'production',
        ]);

        ClientRepository::create([
            'project_id' => $project->id,
            'display_name' => 'Fixture repo',
            'source_type' => 'local',
            'root_path' => $repoPath,
            'status' => 'connected',
            'created_by' => $owner->id,
        ]);

        return [$project, $repoPath];
    }

    /** Test-friendly agent limits (fast, bounded). */
    protected function fastAgentConfig(): void
    {
        config([
            'agent.allow_loopback_endpoints' => true,
            'agent.limits.event_idle_timeout' => 2,
            'agent.limits.max_stream_reconnects' => 5,
            'agent.limits.max_events_per_task' => 500,
            'agent.workspaces.retention_days' => 1,
        ]);
    }

    protected function cleanupFixtureRepo(string $path): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
