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
use Illuminate\Support\Facades\DB;

/**
 * The human-approval gate. Approval is bound to project + task + workspace +
 * base revision + changeset FINGERPRINT + approver + timestamp, is single
 * use, and is revalidated immediately before apply — an approval can never be
 * replayed for different content, and source that moved under the task is
 * STALE, never silently applied.
 */
class AgentApprovalService
{
    public function __construct(protected AgentChangesetService $changesets)
    {
    }

    /**
     * Record an explicit approval of the task's CURRENT changeset.
     *
     * @throws AgentRuntimeException|\Illuminate\Auth\Access\AuthorizationException
     */
    public function approve(AgentTask $task, User $approver, ?string $note = null): AgentApproval
    {
        Access::for($approver)->authorize(Capability::AGENTS_APPROVE, 'project', $task->workspace_id, $task->project_id);

        if ($task->status !== AgentTask::STATUS_AWAITING_APPROVAL) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::SESSION_FAILED,
                'Task is not awaiting approval.'
            );
        }

        $changeset = AgentChangeset::where('agent_task_id', $task->id)->latest('created_at')->first();

        if ($changeset === null) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Task has no changeset to approve.'
            );
        }

        $this->changesets->assertApplicable($changeset);

        // One live approval per task: a new approval supersedes an unspent
        // earlier one (the fingerprint is what makes it safe).
        DB::transaction(function () use ($task, $approver, $note, $changeset) {
            AgentApproval::where('agent_task_id', $task->id)
                ->where('status', AgentApproval::STATUS_APPROVED)
                ->whereNull('consumed_at')
                ->update(['status' => AgentApproval::STATUS_REJECTED, 'note' => 'superseded']);

            return AgentApproval::create([
                'agent_task_id' => $task->id,
                'changeset_fingerprint' => $changeset->fingerprint,
                'base_revision' => $changeset->base_revision,
                'approver_id' => $approver->id,
                'status' => AgentApproval::STATUS_APPROVED,
                'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
            ]);
        });

        $task->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        AdminAudit::record('AGENT_APPROVAL_GRANTED', $task->project, 'agent_task', $task->id, [
            'changeset_fingerprint' => $changeset->fingerprint,
            'approver_id' => $approver->id,
        ]);

        // Phase G (§G6): the decision appears in the task's human timeline.
        // The audit ledger keeps its own entry — this row is presentation.
        $this->decisionEvent($task, 'approval', 'Approved by '.($approver->name ?? 'an operator'), ['actor' => $approver->name]);

        return AgentApproval::where('agent_task_id', $task->id)
            ->where('status', AgentApproval::STATUS_APPROVED)
            ->where('changeset_fingerprint', $changeset->fingerprint)
            ->latest('created_at')
            ->firstOrFail();
    }

    public function reject(AgentTask $task, User $rejector, ?string $note = null): void
    {
        Access::for($rejector)->authorize(Capability::AGENTS_APPROVE, 'project', $task->workspace_id, $task->project_id);

        AgentApproval::where('agent_task_id', $task->id)
            ->where('status', AgentApproval::STATUS_APPROVED)
            ->whereNull('consumed_at')
            ->update(['status' => AgentApproval::STATUS_REJECTED, 'note' => $note !== null ? mb_substr($note, 0, 1000) : 'rejected by operator']);

        if ($task->status === AgentTask::STATUS_AWAITING_APPROVAL) {
            $task->update(['status' => AgentTask::STATUS_COMPLETED, 'finished_at' => now()]);
        }

        AdminAudit::record('AGENT_APPROVAL_REJECTED', $task->project, 'agent_task', $task->id, [
            'approver_id' => $rejector->id,
        ]);

        // Phase G (§G6/§G12): declining is visible, nothing was applied, and
        // the task's history stays intact for audit.
        $this->decisionEvent($task, 'rejection', 'Changes declined — nothing was applied', [
            'actor' => $rejector->name,
            'note' => $note !== null ? mb_substr($note, 0, 200) : null,
        ]);
    }

    /**
     * Revalidate and consume an approval immediately before apply. Any
     * mismatch is a hard refusal — STALE source, TAMPERED changeset, or a
     * replayed/consumed approval all end here.
     *
     * @throws AgentRuntimeException
     */
    public function revalidateAndConsume(AgentTask $task, AgentApproval $approval, AgentChangeset $changeset): void
    {
        if ($approval->status !== AgentApproval::STATUS_APPROVED) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Approval is not in an approved state.');
        }

        if ($approval->consumed_at !== null) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Approval already used (replay refused).');
        }

        // TAMPERED: recompute the fingerprint from the changeset as it exists
        // NOW and compare with what was approved. Any content change after
        // approval (diff body or base revision) breaks the match.
        $actual = hash('sha256', $changeset->base_revision.'|'.$changeset->diff);
        if (! hash_equals($approval->changeset_fingerprint, $actual)) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Changeset no longer matches the approved fingerprint (tamper refused).');
        }

        if (! hash_equals($approval->base_revision, $changeset->base_revision)) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Changeset base revision moved after approval (stale refused).');
        }

        // STALE: the authoritative source moved on since the workspace was cut.
        $workspace = $task->workspaceRecord;
        $source = $workspace !== null ? AgentWorkspaceService::authoritativeRoot($task->project) : null;
        $head = $source !== null ? trim((string) \Illuminate\Support\Facades\Process::timeout(30)->run(['git', '-C', $source, 'rev-parse', 'HEAD'])->output()) : '';

        if (! hash_equals($approval->base_revision, $head)) {
            throw AgentRuntimeException::make(AgentRuntimeException::SESSION_FAILED, 'Source revision moved since the plan was made (stale refused).');
        }

        $approval->update(['consumed_at' => now()]);
    }

    /** Append one bounded, structured decision event to the task's timeline. */
    protected function decisionEvent(AgentTask $task, string $kind, string $summary, array $payload): void
    {
        try {
            \App\Models\AgentTaskEvent::create([
                'agent_task_id' => $task->id,
                'seq' => (int) (\App\Models\AgentTaskEvent::where('agent_task_id', $task->id)->max('seq') ?? 0) + 1,
                'type' => \App\Models\AgentTaskEvent::TYPE_STATUS,
                'summary' => \Illuminate\Support\Str::limit($summary, 500),
                'payload' => array_merge(['kind' => $kind], $payload),
            ]);
        } catch (\Throwable) {
            // The timeline row must never break the decision itself; the
            // audit ledger already recorded the authoritative entry.
        }
    }
}
