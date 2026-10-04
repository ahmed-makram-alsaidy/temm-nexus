<?php

namespace App\Services\Agent;

use App\Models\AgentChangeset;
use App\Models\AgentTask;
use App\Models\AgentWorkspace;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Support\Facades\Process;

/**
 * Deterministic changesets, cut from the ISOLATED WORKSPACE with plain git —
 * never from the runtime's claims alone. The runtime-reported per-session
 * diff is cross-checked for path safety; the workspace `git diff` against the
 * base revision is the authoritative content, so the same bytes always
 * produce the same fingerprint.
 */
class AgentChangesetService
{
    public function __construct(protected AgentWorkspaceService $workspaces)
    {
    }

    /**
     * Build (or rebuild) the changeset for a task from its workspace.
     * Returns NULL when the workspace carries no changes at all (a truthful
     * "nothing to apply" outcome — never an empty changeset row).
     *
     * @param  list<array{path: string, status: string, additions: int, deletions: int, patch: string}>|null  $runtimeDiffs  runtime-reported diffs for cross-checking
     */
    public function build(AgentTask $task, ?array $runtimeDiffs = null): ?AgentChangeset
    {
        $workspace = $task->workspaceRecord;
        if ($workspace === null || ! is_dir($workspace->path)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Task workspace is missing; no changeset can be built.'
            );
        }

        if ($runtimeDiffs !== null) {
            $this->assertRuntimePathsSafe($runtimeDiffs, $workspace);
        }

        // Intent-to-add so untracked files appear in `git diff`, then the
        // binary-safe full diff against the base revision.
        Process::timeout(60)->run(['git', '-C', $workspace->path, 'add', '-A', '--intent-to-add']);
        $result = Process::timeout(120)->run(['git', '-C', $workspace->path, 'diff', '--binary', 'HEAD']);

        if (! $result->successful()) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Could not compute the workspace diff.'
            );
        }

        $diff = $result->output();

        if (trim($diff) === '') {
            AgentChangeset::where('agent_task_id', $task->id)->delete();

            return null;
        }

        $fingerprint = hash('sha256', $workspace->base_revision.'|'.$diff);

        $maxBytes = (int) config('agent.limits.max_diff_bytes', 512000);
        $truncated = strlen($diff) > $maxBytes;

        [$added, $modified, $deleted, $additions, $deletions] = self::summarize($diff);

        // Replace any previous changeset: a task has exactly ONE current
        // changeset (the latest rebuild wins, with a fresh fingerprint).
        AgentChangeset::where('agent_task_id', $task->id)->delete();

        return AgentChangeset::create([
            'agent_task_id' => $task->id,
            'base_revision' => $workspace->base_revision,
            'fingerprint' => $fingerprint,
            'diff' => $truncated ? substr($diff, 0, $maxBytes) : $diff,
            'files_added' => $added,
            'files_modified' => $modified,
            'files_deleted' => $deleted,
            'additions' => $additions,
            'deletions' => $deletions,
            'truncated' => $truncated,
        ]);
    }

    /**
     * Every runtime-reported path must be a safe relative path inside the
     * workspace. One escape attempt poisons the whole changeset.
     *
     * @param  list<array{path: string, ...}>  $runtimeDiffs
     * @throws AgentRuntimeException
     */
    protected function assertRuntimePathsSafe(array $runtimeDiffs, AgentWorkspace $workspace): void
    {
        foreach ($runtimeDiffs as $entry) {
            $path = is_string($entry['path'] ?? null) ? $entry['path'] : '';
            try {
                AgentWorkspaceService::validateRelativePath($path, $workspace->path);
            } catch (AgentRuntimeException $e) {
                throw AgentRuntimeException::make(
                    AgentRuntimeException::WORKSPACE_FAILED,
                    'Runtime reported a change outside the workspace boundary.'
                );
            }
        }
    }

    /**
     * Refuse anything that must not be applied automatically.
     *
     * @throws AgentRuntimeException
     */
    public function assertApplicable(AgentChangeset $changeset): void
    {
        $workspace = $changeset->task->workspaceRecord;

        if ($changeset->truncated) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Changeset exceeds the size limit and requires manual review.'
            );
        }

        if ($workspace !== null) {
            foreach (self::changedPaths($changeset->diff) as $path) {
                AgentWorkspaceService::validateRelativePath($path, $workspace->path);
            }
        }
    }

    /** @return list<string> file paths appearing in a unified diff (a/ side). */
    public static function changedPaths(string $diff): array
    {
        $paths = [];
        if (preg_match_all('/^diff --git a\/(.+?) b\//m', $diff, $m)) {
            $paths = $m[1];
        }

        return array_values(array_unique($paths));
    }

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} added, modified, deleted, additions, deletions */
    public static function summarize(string $diff): array
    {
        $added = $modified = $deleted = $additions = $deletions = 0;

        $lines = explode("\n", $diff);
        $inHunk = false;
        foreach ($lines as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $inHunk = false;

                continue;
            }
            if (str_starts_with($line, 'new file mode')) {
                $added++;

                continue;
            }
            if (str_starts_with($line, 'deleted file mode')) {
                $deleted++;

                continue;
            }
            if (str_starts_with($line, '+++ ') || str_starts_with($line, '--- ')) {
                $inHunk = false;

                continue;
            }
            if (str_starts_with($line, '@@')) {
                $inHunk = true;

                continue;
            }
            if ($inHunk) {
                if (str_starts_with($line, '+')) {
                    $additions++;
                } elseif (str_starts_with($line, '-')) {
                    $deletions++;
                }
            }
        }

        // Files that are neither added nor deleted were modified.
        $total = count(self::changedPaths($diff));
        $modified = max(0, $total - $added - $deleted);

        return [$added, $modified, $deleted, $additions, $deletions];
    }
}
