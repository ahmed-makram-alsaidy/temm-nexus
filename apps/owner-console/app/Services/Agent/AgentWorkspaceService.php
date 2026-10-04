<?php

namespace App\Services\Agent;

use App\Models\AgentTask;
use App\Models\AgentWorkspace;
use App\Models\Project;
use App\Services\Agent\Contract\AgentRuntimeException;
use App\Services\ControlPlane\ControlPlanePaths;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Mandatory workspace isolation for agent tasks.
 *
 * A task NEVER works inside the authoritative project source. For Git-backed
 * projects we cut a disposable `git worktree` from the current revision into
 * TEMM's private storage (volume-backed, not web-reachable). The runtime is
 * pointed at that directory and nothing else.
 *
 * Protections implemented here:
 *  - unique directory per task (no stale-workspace reuse);
 *  - realpath containment: the workspace must resolve inside the workspace
 *    root (defeats symlink escapes and absolute-path tricks);
 *  - relative-path validation for anything applied back into source;
 *  - `.git` metadata is never part of a changeset;
 *  - retention sweeps clean expired workspaces (tree + worktree metadata).
 */
class AgentWorkspaceService
{
    /** Root under which every agent workspace must live. */
    public static function root(): string
    {
        $root = storage_path('app/private/agent-workspaces');

        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        return realpath($root) ?: $root;
    }

    /** Authoritative checkout for a project (operator-approved local repo root wins over the default layout). */
    public static function authoritativeRoot(Project $project): string
    {
        $repo = $project->clientRepositories()->where('source_type', 'local')->orderByDesc('updated_at')->first();

        $candidate = $repo?->root_path ?: ControlPlanePaths::projectDir($project->slug);

        if (! is_string($candidate) || $candidate === '') {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Project has no local source checkout configured.'
            );
        }

        $real = realpath($candidate);

        if ($real === false || ! is_dir($real)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Project source checkout is missing on disk.'
            );
        }

        return $real;
    }

    /**
     * Create an isolated worktree for a task and record it.
     *
     * @throws AgentRuntimeException when the source is not git-backed or the worktree cannot be created
     */
    public function createForTask(AgentTask $task, Project $project): AgentWorkspace
    {
        $source = self::authoritativeRoot($project);

        if (! is_file($source.'/.git') && ! is_dir($source.'/.git')) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Agent tasks require a Git-backed project checkout.'
            );
        }

        $baseRevision = trim((string) Process::timeout(30)
            ->run(['git', '-C', $source, 'rev-parse', 'HEAD'])->output());

        if (! preg_match('/^[0-9a-f]{40}$/i', $baseRevision)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Could not resolve the project source revision.'
            );
        }

        $workspacePath = self::root().DIRECTORY_SEPARATOR.$project->id.DIRECTORY_SEPARATOR.$task->code.'-'.Str::lower(Str::random(8));

        // Refuse to reuse a path that already exists (stale-workspace swap guard).
        if (file_exists($workspacePath)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Workspace path collision; refusing to reuse an existing directory.'
            );
        }

        $result = Process::timeout(120)->run([
            'git', '-C', $source, 'worktree', 'add', '--detach', $workspacePath, $baseRevision,
        ]);

        if (! $result->successful() || ! is_dir($workspacePath)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Could not create the isolated worktree.'
            );
        }

        $real = realpath($workspacePath);
        self::assertInsideRoot($real);

        return AgentWorkspace::create([
            'agent_task_id' => $task->id,
            'project_id' => $project->id,
            'path' => $real,
            'base_revision' => $baseRevision,
            'status' => AgentWorkspace::STATUS_ACTIVE,
            'retain_until' => now()->addDays((int) config('agent.workspaces.retention_days', 7)),
        ]);
    }

    /**
     * Guard: an absolute path must resolve inside the workspace root.
     *
     * @throws AgentRuntimeException
     */
    public static function assertInsideRoot(?string $path): void
    {
        $root = self::root();
        $real = $path !== null ? realpath($path) : false;

        if ($real === false || ! str_starts_with($real.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Workspace path escaped the workspace root.'
            );
        }
    }

    /**
     * Guard: a relative path from a changeset/diff is safe to address inside
     * a workspace (no traversal, no absolute, no `.git`, and no path segment
     * that is (or contains) a symlink pointing outside the workspace).
     *
     * @throws AgentRuntimeException
     */
    public static function validateRelativePath(string $relative, string $workspacePath): string
    {
        $relative = str_replace('\\', '/', trim($relative));

        if ($relative === '') {
            throw AgentRuntimeException::make(AgentRuntimeException::WORKSPACE_FAILED, 'Empty path.');
        }

        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            throw AgentRuntimeException::make(AgentRuntimeException::WORKSPACE_FAILED, 'Path traversal rejected.');
        }

        if (str_starts_with($relative, '/') || preg_match('#^[a-zA-Z]:(/|$)#', $relative)) {
            throw AgentRuntimeException::make(AgentRuntimeException::WORKSPACE_FAILED, 'Absolute path rejected.');
        }

        $relative = ltrim($relative, '/');

        if ($relative === '.git' || str_starts_with($relative, '.git/')) {
            throw AgentRuntimeException::make(AgentRuntimeException::WORKSPACE_FAILED, 'VCS metadata is not addressable.');
        }

        // Symlink escape check: every existing ancestor must resolve inside
        // the workspace.
        $segments = explode('/', $relative);
        array_pop($segments); // the file itself may not exist yet
        $probe = $workspacePath;
        foreach ($segments as $segment) {
            $probe .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($probe)) {
                $target = realpath($probe);
                $realRoot = realpath($workspacePath) ?: $workspacePath;
                if ($target === false || ! str_starts_with($target, $realRoot.DIRECTORY_SEPARATOR)) {
                    throw AgentRuntimeException::make(
                        AgentRuntimeException::WORKSPACE_FAILED,
                        'Symlink escape rejected.'
                    );
                }
            }
        }

        return $relative;
    }

    /** Release a workspace: drop the worktree metadata from the source repo. */
    public function release(AgentWorkspace $workspace): void
    {
        if ($workspace->status === AgentWorkspace::STATUS_CLEANED) {
            return;
        }

        $task = $workspace->task()->first();
        $project = $task?->project()->first();

        if ($project !== null) {
            $source = self::authoritativeRootSafe($project);
            if ($source !== null) {
                // Best effort — a missing worktree is fine at cleanup time.
                Process::timeout(60)->run(['git', '-C', $source, 'worktree', 'remove', '--force', $workspace->path]);
            }
        }

        $workspace->update(['status' => AgentWorkspace::STATUS_RELEASED]);
    }

    /**
     * Retention sweep: clean workspaces past their retention window.
     *
     * @return int number of workspaces cleaned
     */
    public function sweepExpired(): int
    {
        $cleaned = 0;

        AgentWorkspace::query()
            ->where('status', '!=', AgentWorkspace::STATUS_CLEANED)
            ->whereNotNull('retain_until')
            ->where('retain_until', '<', now())
            ->chunkById(50, function ($workspaces) use (&$cleaned) {
                foreach ($workspaces as $workspace) {
                    $this->release($workspace);
                    self::deleteTree($workspace->path);
                    $workspace->update([
                        'status' => AgentWorkspace::STATUS_CLEANED,
                        'cleaned_at' => now(),
                    ]);
                    $cleaned++;
                }
            });

        return $cleaned;
    }

    /** Recursively delete a directory that MUST be inside the workspace root. */
    public static function deleteTree(string $path): void
    {
        try {
            self::assertInsideRoot($path);
        } catch (AgentRuntimeException) {
            return; // refuse anything outside the root — never delete blindly
        }

        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            // Never follow symlinks while deleting.
            $item->isLink() ? @unlink($item->getPathname()) : ($item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()));
        }

        @rmdir($path);
    }

    /** Non-throwing variant of authoritativeRoot for cleanup paths. */
    protected static function authoritativeRootSafe(Project $project): ?string
    {
        try {
            return self::authoritativeRoot($project);
        } catch (AgentRuntimeException) {
            return null;
        }
    }
}
