<?php

namespace App\Services\ControlPlane\Cutover;

use App\Models\CutoverApproval;
use App\Models\CutoverEvent;
use App\Models\CutoverPlan;
use App\Models\MigrationRun;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Connectors\Contracts\CdcCaptureProvider;
use App\Services\ControlPlane\Migration\Cdc\CdcPositionSource;
use App\Services\ControlPlane\Migration\Cdc\CdcRunContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Phase 34 — the Cutover Center.
 *
 * A CONTROL ROOM, not an executor: it verifies gates, records explicit
 * approvals, generates the ordered cutover plan and the rollback plan, and
 * audits every event (34F). The platform NEVER performs DNS changes,
 * endpoint switches, provider shutdowns or production deploys itself —
 * those steps are recorded as operator actions with UNVERIFIED/holding
 * states until the operator confirms completion (34D).
 *
 * Readiness states (34B) are honest: PASS / WARN / BLOCK / NOT_APPLICABLE /
 * UNVERIFIED. A gate with no evidence is UNVERIFIED, never green.
 */
class CutoverCenterService
{
    public const GATE_STATES = ['PASS', 'WARN', 'BLOCK', 'NOT_APPLICABLE', 'UNVERIFIED'];

    /** Gates that require an explicit human approval record (34D). */
    public const APPROVAL_REQUIRED = ['backup_restore_drill', 'final_delta', 'endpoint_switch', 'source_freeze'];

    /**
     * Build the preflight + plan for a project from its latest migration run.
     *
     * @return CutoverPlan
     */
    public function createPlan(Project $project, ?MigrationRun $run = null): CutoverPlan
    {
        $gates = $this->preflight($project, $run);
        $steps = $this->orderedSteps($gates);
        $plan = CutoverPlan::create([
            'project_id' => $project->id,
            'migration_run_id' => $run?->id,
            'run_id' => (string) Str::uuid(),
            'status' => 'draft',
            'gates' => $gates,
            'steps' => $steps,
            'rollback' => $this->rollbackPlan($project, $run),
            'rollback_expiry' => now()->addDays(14),
        ]);
        $this->audit($plan, 'plan_created', ['gates' => collect($gates)->pluck('state', 'gate')->all()]);

        return $plan;
    }

    /**
     * 34A/34B — preflight gates. Every gate carries EVIDENCE; missing
     * evidence is UNVERIFIED, failing evidence is BLOCK.
     */
    public function preflight(Project $project, ?MigrationRun $run = null): array
    {
        $latestRun = $run ?? MigrationRun::query()
            ->whereHas('plan', fn ($q) => $q->where('project_id', $project->id))
            ->orderByDesc('id')->first();

        $gates = [];

        // Target verification: the platform's own doctor/health surface.
        $gates[] = [
            'gate' => 'target_health', 'section' => 'Target',
            'state' => 'UNVERIFIED',
            'evidence' => 'Run platform:doctor before the window; the cutover center does not guess health.',
        ];

        // Backup gate: a VERIFIED + restore-drilled backup is REQUIRED for a
        // PASS — without proof this gate BLOCKS the plan (34A: no fake green).
        $verifiedBackup = \App\Models\BackupRecord::query()
            ->where('db_name', $project->db_name)
            ->where('status', 'ok')
            ->where('restore_test_status', 'ok')
            ->whereNotNull('verified_at')
            ->exists();
        $gates[] = [
            'gate' => 'backup_verified', 'section' => 'Backups',
            'state' => $verifiedBackup ? 'PASS' : 'BLOCK',
            'evidence' => $verifiedBackup ? 'verified, restore-drilled backup on record' : 'no VERIFIED backup with a passed restore drill — drill required',
        ];

        // Data gate: latest rehearsal result.
        if ($latestRun !== null && $latestRun->status === 'completed') {
            $gates[] = ['gate' => 'data_rehearsal', 'section' => 'Data', 'state' => 'PASS',
                'evidence' => "rehearsal run {$latestRun->run_id} completed"];
        } else {
            $gates[] = ['gate' => 'data_rehearsal', 'section' => 'Data', 'state' => $latestRun === null ? 'UNVERIFIED' : 'BLOCK',
                'evidence' => $latestRun === null ? 'no migration run on record' : 'last run did not complete ('.($latestRun->status ?? 'unknown').')'];
        }

        // CDC gate (35.6 §16-18): when a REAL log-based stream has been
        // reporting for this run, its signed telemetry decides PASS/WARN/
        // BLOCK. Without one the gate stays UNVERIFIED — watermark
        // incremental export is NOT log-based CDC and never passes here.
        if ($latestRun === null) {
            $gates[] = ['gate' => 'cdc_lag', 'section' => 'CDC', 'state' => 'UNVERIFIED',
                'evidence' => 'no migration run on record — no CDC stream to measure'];
        } else {
            $cdc = (new CdcLagEvaluator)->evaluate($latestRun->id);
            $gates[] = ['gate' => 'cdc_lag', 'section' => 'CDC',
                'state' => $cdc['state'], 'evidence' => $cdc['evidence'], 'detail' => $cdc['detail']];
        }

        // Client readiness: remaining legacy callsites on linked repositories.
        $legacy = \App\Models\ClientCallsite::query()
            ->whereHas('repository', fn ($q) => $q->where('project_id', $project->id))
            ->where('status', 'DISCOVERED')->count();
        $gates[] = ['gate' => 'client_readiness', 'section' => 'Client readiness',
            'state' => $legacy === 0 ? 'PASS' : 'WARN',
            'evidence' => $legacy === 0 ? 'no legacy callsites remain' : "{$legacy} legacy callsite(s) not yet converted"];

        // DNS/API endpoint readiness: ALWAYS operator-owned (34D).
        $gates[] = ['gate' => 'endpoint_switch', 'section' => 'DNS/API endpoint readiness',
            'state' => 'UNVERIFIED', 'evidence' => 'operator-owned change; recorded, never executed by the platform'];

        // Rollback readiness: expiry window armed.
        $gates[] = ['gate' => 'rollback_ready', 'section' => 'Rollback readiness',
            'state' => 'PASS', 'evidence' => 'rollback plan generated with a 14-day expiry window'];

        return $gates;
    }

    /** 34C — the ordered plan; steps gated on the approval matrix. */
    protected function orderedSteps(array $gates): array
    {
        $blocking = collect($gates)->where('state', 'BLOCK')->pluck('gate')->all();

        return [
            ['step' => 1, 'action' => 'verify_backup', 'note' => $blocking !== [] ? 'BLOCKED until: '.implode(', ', $blocking) : 'verify backup checksums'],
            ['step' => 2, 'action' => 'verify_target', 'note' => 'platform:doctor + target health'],
            ['step' => 3, 'action' => 'verify_snapshot', 'note' => 'snapshot boundary recorded'],
            ['step' => 4, 'action' => 'verify_cdc_lag', 'note' => 'live lag measurement (32F)'],
            ['step' => 5, 'action' => 'source_freeze', 'approval_required' => true, 'note' => 'maintenance/freeze window if required'],
            ['step' => 6, 'action' => 'final_delta', 'approval_required' => true, 'note' => 'final incremental export + apply'],
            ['step' => 7, 'action' => 'reconcile', 'note' => 'validator suite over the target'],
            ['step' => 8, 'action' => 'endpoint_switch', 'approval_required' => true, 'note' => 'OPERATOR-executed DNS/endpoint switch — recorded, never automatic'],
            ['step' => 9, 'action' => 'smoke_test', 'note' => 'platform smoke checks against the new endpoint'],
            ['step' => 10, 'action' => 'observe', 'note' => 'observation period per rollback plan'],
            ['step' => 11, 'action' => 'complete_or_rollback', 'note' => 'complete the window or roll back before expiry'],
        ];
    }

    /** 34E — rollback plan (what to restore, where, until when). */
    protected function rollbackPlan(Project $project, ?MigrationRun $run): array
    {
        return [
            'backup_reference' => 'latest verified backup for project '.$project->id,
            'old_endpoint' => 'operator-recorded current endpoint',
            'cutover_timestamp' => null,
            'migration_checkpoint' => $run?->run_id,
            'rollback_expiry_days' => 14,
            'procedure' => 're-point endpoint to the old system, freeze writes on the target, replay post-checkpoint source changes',
        ];
    }

    /** 34D — record an explicit approval for a gate; nothing auto-approves. */
    public function approve(CutoverPlan $plan, string $gate, string $decision, ?string $note = null): CutoverApproval
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422, 'decision must be approved|rejected');
        abort_unless(in_array($gate, self::APPROVAL_REQUIRED, true), 422, "gate '{$gate}' does not take approvals");

        $approval = CutoverApproval::create([
            'cutover_plan_id' => $plan->id,
            'gate' => $gate,
            'decision' => $decision,
            'note' => $note,
            'approved_by' => Auth::id(),
            'decided_at' => now(),
        ]);
        $this->audit($plan, 'approval', ['gate' => $gate, 'decision' => $decision]);

        return $approval;
    }

    /**
     * 34D — record a step execution. Production-affecting steps REFUSE to
     * be recorded as done without an explicit approval on file.
     */
    public function recordStep(CutoverPlan $plan, string $action, array $result = []): void
    {
        if (in_array($action, self::APPROVAL_REQUIRED, true)) {
            $approved = $plan->approvals()->where('gate', $action)->where('decision', 'approved')->exists();
            abort_unless($approved, 422, "step '{$action}' requires an explicit approval record (34D)");
        }
        $this->audit($plan, 'step_recorded', ['action' => $action] + $result);
    }

    /** 34F — audit trail with request id. */
    protected function audit(CutoverPlan $plan, string $event, array $details): void
    {
        CutoverEvent::create([
            'cutover_plan_id' => $plan->id,
            'event' => $event,
            'details' => $details,
            'actor_id' => Auth::id(),
            'request_id' => request()->header('X-Request-Id') ?? (string) Str::uuid(),
        ]);
        AdminAudit::record('CUTOVER_EVENT', $plan->project, 'cutover_plan', $plan->id, [
            'event' => $event,
        ]);
    }

    // ── Phase 35.6 §19 — the FINAL SYNC workflow ─────────────────────────

    /**
     * Capture the FINAL SOURCE POSITION (the freeze boundary) and verify
     * the applied side has reached it. The OPERATOR freezes writes — the
     * platform never does (34D) — and never switches endpoints.
     *
     * Sequence: preflight already green → operator requests final sync →
     * capture final position → run capture cycles (bounded) until the
     * applied position covers it → record audit events. DATA_READY_FOR_
     * CUTOVER is returned only when the applied position has covered the
     * final source position.
     *
     * @return array{final_position: array, applied_through: bool, captured_events: int, evidence: string}
     */
    public function finalDelta(CutoverPlan $plan, int $maxWaitSeconds = 300): array
    {
        $run = $plan->migration_run_id !== null
            ? MigrationRun::find($plan->migration_run_id)
            : null;
        abort_if($run === null, 422, 'the cutover plan has no linked migration run');

        // The context builder refuses honestly when the connector has no
        // real log-based capture (422 — operator sees the truth).
        $context = CdcRunContext::forRun($run);
        abort_unless($context->capture instanceof CdcPositionSource, 422, 'the capture does not expose source positions — final sync is impossible');

        /** @var CdcPositionSource $capture */
        $capture = $context->capture;

        // 1. FREEZE BOUNDARY: the current source position. The operator's
        // write freeze must be in effect (approved gate) — recorded here.
        $final = $capture->currentSourcePosition();
        $this->audit($plan, 'final_position_captured', [
            'label' => $final['label'],
            'captured_at' => $final['captured_at'],
            'mechanism' => $context->capture->checkpointKind(),
        ]);

        // 2. CATCH UP: run capture cycles until the applied position covers
        // the final position (or the wait budget is exhausted — honest).
        $checkpoint = $context->worker->currentPosition();
        $appliedThrough = $capture->hasAppliedThrough($checkpoint?->position, $final['position']);
        $deadline = now()->addSeconds(max(5, $maxWaitSeconds));
        $cycles = 0;
        while (! $appliedThrough && now()->lt($deadline)) {
            $context->worker->run(1, null, 1);
            $cycles++;
            $checkpoint = $context->worker->currentPosition();
            $appliedThrough = $capture->hasAppliedThrough($checkpoint?->position, $final['position']);
        }

        $this->audit($plan, 'final_delta_result', [
            'applied_through' => $appliedThrough,
            'cycles' => $cycles,
            'applied_events' => $context->worker->appliedEvents(),
        ]);

        return [
            'final_position' => $final,
            'applied_through' => $appliedThrough,
            'captured_events' => $context->worker->capturedEvents(),
            'evidence' => $appliedThrough
                ? "target applied through the final source position ({$final['label']}) — DATA_READY_FOR_CUTOVER"
                : "target has NOT applied through {$final['label']} within {$maxWaitSeconds}s — do not cut over",
        ];
    }
}
