<?php

namespace App\Services\Agent;

use App\Models\AgentApproval;
use App\Models\AgentChangeset;
use App\Models\AgentTask;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\ControlPlane\AdminAudit;
use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Support\Facades\Process;

/**
 * Applies an approved changeset to the authoritative source using
 * repository-native operations (git apply) — never a recursive copy.
 *
 * Ordering guarantees:
 *  1. approval revalidated + consumed (single-use, fingerprint- and
 *     revision-bound — see AgentApprovalService);
 *  2. `git apply --check` dry-run of the ENTIRE patch (atomicity gate);
 *  3. a stash snapshot of the pre-apply state is recorded in the audit
 *     metadata for operator recovery;
 *  4. the whole patch is applied in one `git apply` invocation — if git
 *     refuses, NOTHING is applied; if the process dies mid-apply, the
 *     failure is reported honestly and the task is marked failed, never
 *     "successful".
 */
class AgentApplyService
{
    public function __construct(
        protected AgentApprovalService $approvals,
        protected AgentChangesetService $changesets,
    ) {
    }

    public function apply(AgentTask $task, User $operator, ?int $approvalId = null): AgentChangeset
    {
        Access::for($operator)->authorize(Capability::AGENTS_APPLY, 'project', $task->workspace_id, $task->project_id);

        if ($task->status !== AgentTask::STATUS_AWAITING_APPROVAL) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Task is not ready to apply.');
        }

        $changeset = AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first();

        if ($changeset === null) {
            throw AgentRuntimeException::make(AgentRuntimeException::WORKSPACE_FAILED, 'Task has no changeset.');
        }

        $this->changesets->assertApplicable($changeset);

        $approval = AgentApproval::where('agent_task_id', $task->id)
            ->when($approvalId !== null, fn ($q) => $q->where('id', $approvalId))
            ->where('status', AgentApproval::STATUS_APPROVED)
            ->latest('created_at')
            ->first();

        if ($approval === null) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'No approval exists for this task.');
        }

        $this->approvals->revalidateAndConsume($task, $approval, $changeset);

        $workspace = $task->workspaceRecord;
        $source = AgentWorkspaceService::authoritativeRoot($task->project);

        $task->update(['status' => AgentTask::STATUS_APPLYING]);

        // 1. Dry-run the whole patch (git apply reads the patch from stdin).
        $check = Process::timeout(120)->input($changeset->diff)
            ->run(['git', '-C', $source, 'apply', '--check', '--whitespace=nowarn', '-']);

        if (! $check->successful()) {
            $task->update([
                'status' => AgentTask::STATUS_AWAITING_APPROVAL,
                'error_category' => AgentRuntimeException::COMMAND_FAILED,
                'error_message' => 'Pre-apply check failed; source may conflict with the changeset.',
            ]);
            AdminAudit::record('AGENT_APPLY_FAILED', $task->project, 'agent_task', $task->id, ['stage' => 'check']);

            throw AgentRuntimeException::make(
                AgentRuntimeException::COMMAND_FAILED,
                'Pre-apply check failed; the changeset does not apply cleanly.'
            );
        }

        // 2. Recovery point snapshot (does not touch the working tree).
        $snapshot = trim((string) Process::timeout(60)->run(['git', '-C', $source, 'stash', 'create'])->output());

        // 3. Apply.
        $apply = Process::timeout(300)->input($changeset->diff)
            ->run(['git', '-C', $source, 'apply', '--whitespace=nowarn', '-']);

        if (! $apply->successful()) {
            $task->update([
                'status' => AgentTask::STATUS_FAILED,
                'finished_at' => now(),
                'error_category' => AgentRuntimeException::COMMAND_FAILED,
                'error_message' => 'Apply failed midway; inspect the repository before retrying.',
            ]);
            AdminAudit::record('AGENT_APPLY_FAILED', $task->project, 'agent_task', $task->id, [
                'stage' => 'apply', 'snapshot' => $snapshot !== '' ? $snapshot : null,
            ]);

            throw AgentRuntimeException::make(
                AgentRuntimeException::COMMAND_FAILED,
                'Applying the approved changeset failed.'
            );
        }

        $appliedFiles = array_slice(AgentChangesetService::changedPaths($changeset->diff), 0, 50);

        $task->update([
            'status' => AgentTask::STATUS_VERIFYING,
            'applied_at' => now(),
        ]);

        AdminAudit::record('AGENT_CHANGESET_APPLIED', $task->project, 'agent_task', $task->id, [
            'files' => count(AgentChangesetService::changedPaths($changeset->diff)),
            'paths' => $appliedFiles,
            'snapshot' => $snapshot !== '' ? $snapshot : null,
        ]);

        foreach ($appliedFiles as $path) {
            AdminAudit::record('AGENT_FILE_APPLIED', $task->project, 'agent_task', $task->id, ['path' => $path]);
        }

        // Phase G (§G6): the visible lifecycle step "Changes applied".
        try {
            \App\Models\AgentTaskEvent::create([
                'agent_task_id' => $task->id,
                'seq' => (int) (\App\Models\AgentTaskEvent::where('agent_task_id', $task->id)->max('seq') ?? 0) + 1,
                'type' => \App\Models\AgentTaskEvent::TYPE_STATUS,
                'summary' => 'Changes applied to the repository',
                'payload' => ['kind' => 'applied', 'files' => count($appliedFiles)],
            ]);
        } catch (\Throwable) {
            // The timeline row must never break the apply.
        }

        return $changeset;
    }
}
