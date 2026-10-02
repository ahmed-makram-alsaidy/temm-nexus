<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiPatchFile;
use App\Models\AiPatchRun;
use App\Models\ClientRepository;
use App\Models\CopilotRun;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Repository\ClientRepositoryService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Phase 25I — isolated AI code patch workspace.
 *
 * Every AI-generated change lands here FIRST as reviewable patches. Nothing
 * is applied to any working tree without explicit operator approval, and
 * every path is guarded against traversal.
 */
class PatchWorkspace
{
    /** Create a patch run from AI-proposed changes (validated, unapplied). */
    public static function create(Project $project, ?CopilotRun $copilotRun, ?ClientRepository $repo, array $patches, string $targetRoot): AiPatchRun
    {
        abort_if($patches === [], 422, 'No patches proposed.');
        $targetRoot = rtrim(realpath($targetRoot) ?: $targetRoot, '\\/');
        $workspace = storage_path('app/private/ai-workspaces/'.$project->id.'/'.Str::uuid());
        if (! is_dir($workspace)) {
            mkdir($workspace, 0775, true);
        }

        // Validate ALL patches BEFORE creating any run row — refused patches
        // must leave no residue.
        $prepared = [];
        foreach ($patches as $patch) {
            $path = self::validatedRelativePath($patch['path'] ?? '');
            $action = ($patch['action'] ?? 'create') === 'modify' ? 'modify' : 'create';
            $risk = $patch['risk'] ?? 'low';
            if (! in_array($risk, ['low', 'medium', 'high'], true)) {
                $risk = 'low';
            }
            if ($action === 'create') {
                $prepared[] = ['path' => $path, 'action' => $action, 'risk' => $risk, 'content' => (string) ($patch['content'] ?? ''), 'diff' => null,
                    'reason' => (string) ($patch['reason'] ?? ''), 'tests' => (string) ($patch['tests'] ?? '')];
            } else {
                $diff = (string) ($patch['diff'] ?? '');
                abort_if($diff === '', 422, "Modify patch for {$path} requires a unified diff.");
                $prepared[] = ['path' => $path, 'action' => $action, 'risk' => $risk, 'content' => null, 'diff' => $diff,
                    'reason' => (string) ($patch['reason'] ?? ''), 'tests' => (string) ($patch['tests'] ?? '')];
            }
        }

        $run = AiPatchRun::create([
            'project_id' => $project->id,
            'copilot_run_id' => $copilotRun?->id,
            'client_repository_id' => $repo?->id,
            'run_id' => (string) Str::uuid(),
            'workspace_path' => $workspace,
            'target_root' => $targetRoot,
            'status' => 'proposed',
            'created_by' => auth()->id(),
        ]);

        foreach ($prepared as $prep) {
            if ($prep['action'] === 'create') {
                $staged = $workspace.'/'.$prep['path'];
                @mkdir(dirname($staged), 0775, true);
                file_put_contents($staged, $prep['content']);
            }
            AiPatchFile::create([
                'ai_patch_run_id' => $run->id,
                'path' => $prep['path'],
                'action' => $prep['action'],
                'reason' => Str::limit($prep['reason'], 500),
                'risk' => $prep['risk'],
                'diff' => $prep['diff'],
                'tests' => Str::limit($prep['tests'], 500),
                'status' => 'proposed',
            ]);
        }

        AdminAudit::record('PATCH_GENERATED', $project, 'ai_patch_run', $run->id, [
            'files' => count($patches), 'target' => basename($targetRoot),
        ]);

        return $run;
    }

    /** 25I.2 — relative path guard: no traversal, no absolute/system paths. */
    public static function validatedRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        abort_if($path === '', 422, 'Patch path is empty.');
        abort_if(str_contains($path, '..'), 422, "Patch path rejected (traversal): {$path}");
        abort_if(str_starts_with($path, '/'), 422, "Patch path rejected (absolute): {$path}");
        abort_if(preg_match('#^[a-zA-Z]:(/|$)#', $path), 422, "Patch path rejected (absolute): {$path}");
        foreach (['wp-admin', '/etc/', 'C:/Windows', 'system32'] as $needle) {
            abort_if(stripos($path, $needle) !== false, 422, "Patch path rejected (system path): {$path}");
        }

        return ltrim($path, '/');
    }

    /** Review actions (25I.3): approve / reject / request revision. */
    public static function approve(AiPatchRun $run, $user, array $fileIds = []): AiPatchRun
    {
        abort_if($run->status !== 'proposed', 422, 'Patch run is not in review state.');
        $files = $run->files()->whereIn('status', ['proposed'])->get();
        foreach ($files as $file) {
            if ($fileIds === [] || in_array($file->id, $fileIds, true)) {
                $file->update(['status' => 'approved', 'reviewed_by' => $user->id]);
            }
        }
        if ($run->files()->where('status', 'proposed')->count() === 0) {
            $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        }
        AdminAudit::record('PATCH_APPROVED', $run->project, 'ai_patch_run', $run->id, ['files' => count($fileIds) ?: 'all']);

        return $run->fresh();
    }

    public static function reject(AiPatchRun $run, $user, string $reason = ''): AiPatchRun
    {
        abort_if(! in_array($run->status, ['proposed', 'approved'], true), 422, 'Patch run is not rejectable.');
        $run->files()->whereIn('status', ['proposed', 'approved'])->update(['status' => 'rejected', 'reviewed_by' => $user->id]);
        $run->update(['status' => 'rejected']);
        AdminAudit::record('PATCH_REJECTED', $run->project, 'ai_patch_run', $run->id, ['reason' => Str::limit($reason, 120)]);

        return $run->fresh();
    }

    /**
     * 25I.4 — apply approved patches to the approved working tree.
     * Never deletes unrelated files; never uses destructive git commands.
     */
    public static function apply(AiPatchRun $run, $user): AiPatchRun
    {
        abort_if($run->status !== 'approved', 422, 'Patches must be approved before apply.');
        abort_if(! is_dir($run->target_root), 422, 'Approved target root is unavailable.');

        $gitBefore = self::gitStatus($run->target_root);
        $applied = 0;
        $failed = 0;

        foreach ($run->files()->where('status', 'approved')->get() as $file) {
            try {
                if ($file->action === 'create') {
                    self::applyCreate($run, $file);
                } else {
                    self::applyDiff($run, $file);
                }
                $file->update(['status' => 'applied']);
                $applied++;
            } catch (\Throwable $e) {
                $file->update(['status' => 'proposed', 'reason' => 'apply failed: '.Str::limit($e->getMessage(), 200)]);
                $failed++;
            }
        }

        $run->update([
            'status' => $failed === 0 ? 'applied' : ($applied > 0 ? 'applied_partial' : 'failed'),
            'applied_at' => $applied > 0 ? now() : null,
            'git_status' => ['before' => $gitBefore, 'after' => self::gitStatus($run->target_root)],
        ]);
        AdminAudit::record('PATCH_APPLIED', $run->project, 'ai_patch_run', $run->id, [
            'applied' => $applied, 'failed' => $failed,
        ]);

        return $run->fresh();
    }

    protected static function applyCreate(AiPatchRun $run, AiPatchFile $file): void
    {
        $staged = $run->workspace_path.'/'.$file->path;
        abort_if(! file_exists($staged), 422, 'Workspace content missing for '.$file->path);
        $target = ClientRepositoryService::safePathForRoot($run->target_root, $file->path, createIfMissing: true);
        // Never clobber an existing unrelated file on create.
        clearstatcache(true, $target);
        if (file_exists($target) && hash_file('sha256', $staged) !== hash_file('sha256', $target) && filesize($target) > 0) {
            abort(422, "Create refused — {$file->path} already exists with different content.");
        }
        @mkdir(dirname($target), 0775, true);
        copy($staged, $target);
    }

    protected static function applyDiff(AiPatchRun $run, AiPatchFile $file): void
    {
        $target = ClientRepositoryService::safePathForRoot($run->target_root, $file->path);
        $original = (string) file_get_contents($target);
        $patched = self::applyUnifiedDiff($original, (string) $file->diff);
        if ($patched === null) {
            throw new \RuntimeException('unified diff did not apply cleanly');
        }
        file_put_contents($target, $patched);
    }

    /**
     * Minimal deterministic unified-diff applier for standard `diff -u` hunks
     * (a--- b--- headers ignored; @@ -l,c +l,c @@ hunks; context/-/+ lines).
     */
    public static function applyUnifiedDiff(string $original, string $diff): ?string
    {
        $lines = preg_split("/\r\n|\n|\r/", $original);
        $diffLines = preg_split("/\r\n|\n|\r/", $diff);
        $hunks = [];
        $current = null;
        foreach ($diffLines as $line) {
            if (preg_match('/^@@\s*-(\d+)(?:,(\d+))?\s*\+(\d+)(?:,(\d+))?\s*@@/', $line, $m)) {
                $current = ['start' => max(0, (int) $m[1] - 1), 'ops' => []];
                $hunks[] = &$current;
            } elseif ($current !== null && ($line === '' || $line[0] === ' ' || $line[0] === '-' || $line[0] === '+') && ! str_starts_with($line, '---') && ! str_starts_with($line, '+++')) {
                $op = $line === '' ? ' ' : $line[0];
                $current['ops'][] = [$op, mb_substr($line, 1)];
            } elseif (preg_match('/^(---|\+\+\+|diff |index |new file|deleted file)/', $line)) {
                continue; // header noise
            }
        }
        if ($hunks === []) {
            return null;
        }

        // Apply from the bottom up so line numbers stay valid.
        foreach (array_reverse($hunks) as $hunk) {
            $pos = $hunk['start'];
            $expect = [];
            foreach ($hunk['ops'] as [$op, $text]) {
                if ($op === ' ' || $op === '-') {
                    $expect[] = $text;
                }
            }
            foreach ($expect as $i => $expected) {
                $actual = $lines[$pos + $i] ?? null;
                if (rtrim((string) $actual) !== rtrim($expected)) {
                    return null; // context mismatch — refuse
                }
            }
            $replacement = [];
            foreach ($hunk['ops'] as [$op, $text]) {
                if ($op === ' ' || $op === '+') {
                    $replacement[] = $text;
                }
            }
            $removeCount = count($expect);
            array_splice($lines, $pos, $removeCount, $replacement);
        }

        return implode("\n", $lines);
    }

    protected static function gitStatus(string $root): ?array
    {
        if (! is_dir($root.'/.git')) {
            return null;
        }
        $result = Process::path($root)->timeout(30)->run('git status --porcelain');
        if (! $result->successful()) {
            return null;
        }

        return array_slice(explode("\n", trim($result->output())), 0, 100);
    }
}
