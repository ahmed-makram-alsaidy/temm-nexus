<?php

namespace App\Services\Product;

use App\Models\BackupRecord;
use App\Models\CutoverApproval;
use App\Models\CutoverPlan;
use App\Models\Project;
use App\Models\ReadinessCheck;
use App\Models\User;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 §10 — the Cutover readiness experience.
 *
 * Cutover is the critical product moment: it is the point at which a client's
 * production is switched. v0.3.0 had no screen called "Cutover" at all — the
 * gates existed inside the Cutover Center service and a generic Readiness page,
 * so a user could not answer "can I go, and if not, why not" in one place.
 *
 * WHAT THIS ASSEMBLES (the mission's list, verbatim)
 *   overall readiness · blocking issues · CDC state · lag · reconciliation ·
 *   validation · backup status · rollback readiness · final sync readiness ·
 *   human approvals
 *
 * HONESTY RULES CARRIED OVER FROM THE EXISTING SERVICE
 *  - A gate with no evidence is UNVERIFIED, never green.
 *  - The platform never executes DNS/endpoint changes; those are operator-owned
 *    and are recorded, not performed.
 *  - `overall()` never reports READY while any gate is BLOCK or UNVERIFIED.
 *
 * SCOPE: every query is for ONE project whose reach the caller has already
 * authorised. This class performs no authorisation of its own.
 */
final class CutoverReadiness
{
    /** The three states the mission specifies for this screen. */
    public const READY = 'READY';

    public const WARNING = 'WARNING';

    public const BLOCKED = 'BLOCKED';

    public function __construct(
        private readonly Project $project,
    ) {}

    public static function for(Project $project): self
    {
        return new self($project);
    }

    // ─────────────────────────────────────────────────────────────────
    // The headline
    // ─────────────────────────────────────────────────────────────────

    /**
     * Overall readiness, as one of READY / WARNING / BLOCKED.
     *
     * UNVERIFIED degrades to WARNING rather than READY: "we could not check"
     * must never present as "you may proceed". A single BLOCK is enough to
     * block the whole window.
     *
     * The returned shape is the same `{label, detail, ...}` contract the other
     * dimensions use, so callers and tests do not have to special-case it.
     *
     * @return array{state: string, label: string, detail: string, reason: ?string, tone: string}
     */
    public function overall(): array
    {
        $gates = $this->gates();
        $blocking = $this->blockingGates($gates);
        $unverified = $this->unverifiedGates($gates);
        $warnings = $this->warningGates($gates);

        if ($blocking !== []) {
            return [
                'state' => self::BLOCKED,
                'label' => 'Blocked',
                'tone' => 'danger',
                'detail' => count($blocking) === 1
                    ? 'One gate is blocking this cutover.'
                    : count($blocking).' gates are blocking this cutover.',
                'reason' => $blocking[0]['evidence'] ?? null,
            ];
        }

        if ($unverified !== [] || $warnings !== []) {
            $count = count($unverified) + count($warnings);
            $first = $unverified[0] ?? $warnings[0];

            return [
                'state' => self::WARNING,
                'label' => 'Warning',
                'tone' => 'warning',
                'detail' => $count === 1
                    ? 'One item needs attention before you switch.'
                    : $count.' items need attention before you switch.',
                'reason' => $first['evidence'] ?? null,
            ];
        }

        return [
            'state' => self::READY,
            'label' => 'Ready',
            'tone' => 'success',
            'detail' => 'Every gate passes. You can proceed when the window opens.',
            'reason' => null,
        ];
    }

    public function isReady(): bool
    {
        return $this->overall()['state'] === self::READY;
    }

    // ─────────────────────────────────────────────────────────────────
    // Gates
    // ─────────────────────────────────────────────────────────────────

    /**
     * The preflight gates, each translated onto the three product states while
     * KEEPING the underlying gate state for the Advanced disclosure (§14).
     *
     * @return list<array{
     *     gate: string, section: string, state: string, product_state: string,
     *     label: string, tone: string, icon: string, evidence: string,
     *     detail: ?string, requires_approval: bool, approval: ?array
     * }>
     */
    public function gates(): array
    {
        $plan = $this->plan();
        $raw = $this->preflightGates();

        $out = [];
        foreach ($raw as $gate) {
            $rawState = strtoupper((string) ($gate['state'] ?? 'UNVERIFIED'));
            $product = $this->toProductState($rawState);
            $name = (string) ($gate['gate'] ?? 'unknown');

            $out[] = [
                'gate' => $name,
                'section' => (string) ($gate['section'] ?? 'General'),
                'state' => $rawState,
                'product_state' => $product,
                'label' => $this->productLabel($rawState),
                'tone' => $this->productTone($rawState),
                'icon' => $this->productIcon($rawState),
                'evidence' => (string) ($gate['evidence'] ?? ''),
                'detail' => $this->detailToString($gate['detail'] ?? null),
                'requires_approval' => in_array($name, CutoverCenterService::APPROVAL_REQUIRED, true),
                'approval' => $plan ? $this->approvalFor($plan, $name) : null,
            ];
        }

        return $out;
    }

    /**
     * A gate's `detail` may be a string OR a structured map — the CDC lag
     * evaluator documents its detail as `array<string,mixed>`. Casting that
     * to string fatals the whole Cutover screen (a real 500, found by the
     * Phase K UX review on any project with a CDC stream). Render a readable
     * line instead; the evidence is never lost.
     */
    private function detailToString(mixed $detail): ?string
    {
        if ($detail === null) {
            return null;
        }

        if (is_string($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $key => $value) {
                $parts[] = is_string($key)
                    ? $key.': '.(is_scalar($value) ? (string) $value : json_encode($value))
                    : (is_scalar($value) ? (string) $value : json_encode($value));
            }

            return implode('; ', array_filter($parts, fn ($p) => $p !== ''));
        }

        return (string) $detail;
    }

    /**
     * Read the preflight gates from the persisted plan when there is one, so
     * the screen shows the gates the operator actually reviewed; otherwise
     * compute them live so the page is useful before a plan exists.
     *
     * @return list<array<string, mixed>>
     */
    private function preflightGates(): array
    {
        $plan = $this->plan();
        if ($plan && is_array($plan->gates) && $plan->gates !== []) {
            return array_values($plan->gates);
        }

        try {
            return array_values((new CutoverCenterService)->preflight($this->project));
        } catch (\Throwable $e) {
            Log::debug('Cutover preflight degraded', [
                'project_id' => $this->project->id,
                'error' => $e->getMessage(),
            ]);

            // Fail closed: no gates measurable means nothing is verified.
            return [[
                'gate' => 'preflight',
                'section' => 'Preflight',
                'state' => 'UNVERIFIED',
                'evidence' => 'Preflight could not be evaluated — treat as not verified.',
            ]];
        }
    }

    /** BLOCK gates. @return list<array<string, mixed>> */
    public function blockingGates(array $gates): array
    {
        return array_values(array_filter($gates, fn (array $g): bool => $g['product_state'] === 'BLOCK'));
    }

    /** @return list<array<string, mixed>> */
    private function warningGates(array $gates): array
    {
        return array_values(array_filter($gates, fn (array $g): bool => $g['product_state'] === 'WARN'));
    }

    /** @return list<array<string, mixed>> */
    private function unverifiedGates(array $gates): array
    {
        return array_values(array_filter($gates, fn (array $g): bool => $g['product_state'] === 'UNVERIFIED'));
    }

    /**
     * Blocking issues, each with WHY (§10 requires the user to understand the
     * reason, not merely that they are blocked).
     *
     * @return list<array{severity: string, title: string, detail: string, gate: string}>
     */
    public function blockingIssues(): array
    {
        $out = [];

        foreach ($this->gates() as $gate) {
            if ($gate['product_state'] === 'BLOCK') {
                $out[] = [
                    'severity' => 'danger',
                    'gate' => $gate['gate'],
                    'title' => $gate['section'].' is blocking',
                    'detail' => $gate['evidence'],
                ];
            } elseif ($gate['product_state'] === 'UNVERIFIED') {
                $out[] = [
                    'severity' => 'warning',
                    'gate' => $gate['gate'],
                    'title' => $gate['section'].' is not verified',
                    'detail' => $gate['evidence'],
                ];
            } elseif ($gate['product_state'] === 'WARN') {
                $out[] = [
                    'severity' => 'warning',
                    'gate' => $gate['gate'],
                    'title' => $gate['section'].' needs review',
                    'detail' => $gate['evidence'],
                ];
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Section: Live Sync, validation, reconciliation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Live Sync state in product language (§14), plus the raw CDC detail for
     * Advanced.
     *
     * @return array{label: string, detail: string, lag_seconds: ?int, tone: string, raw_state: string}
     */
    public function liveSync(): array
    {
        $gate = $this->gateByKey('cdc_lag');
        $pulse = ProjectPulse::for($this->project);
        $sync = $pulse->liveSync();

        return [
            'label' => $sync['label'],
            'detail' => $gate['detail'] ?? $sync['detail'],
            'lag_seconds' => $sync['lagSeconds'],
            'tone' => $gate ? $gate['tone'] : 'neutral',
            // The raw internal gate state, shown only under Advanced.
            'raw_state' => $gate['state'] ?? 'UNKNOWN',
        ];
    }

    /**
     * Reconciliation / validation summary.
     *
     * @return array{label: string, detail: string, tone: string, checks: int, failed: int}
     */
    public function validation(): array
    {
        try {
            $checks = ReadinessCheck::query()
                ->where('project_id', $this->project->id)
                ->get();
        } catch (\Throwable) {
            return ['label' => 'Not verified', 'detail' => 'Validation could not be read.', 'tone' => 'warning', 'checks' => 0, 'failed' => 0];
        }

        if ($checks->isEmpty()) {
            return [
                'label' => 'Not run',
                'detail' => 'No validation has been recorded for this project.',
                'tone' => 'warning',
                'checks' => 0,
                'failed' => 0,
            ];
        }

        $failed = $checks->where('status', 'failed')->count();
        $warned = $checks->where('status', 'warning')->count();

        if ($failed > 0) {
            return [
                'label' => 'Failed',
                'detail' => $failed.' of '.$checks->count().' checks failed.',
                'tone' => 'danger',
                'checks' => $checks->count(),
                'failed' => $failed,
            ];
        }

        if ($warned > 0) {
            return [
                'label' => 'Needs review',
                'detail' => $warned.' of '.$checks->count().' checks need review.',
                'tone' => 'warning',
                'checks' => $checks->count(),
                'failed' => 0,
            ];
        }

        return [
            'label' => 'Reconciled',
            'detail' => 'All '.$checks->count().' checks pass.',
            'tone' => 'success',
            'checks' => $checks->count(),
            'failed' => 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Section: backup + rollback
    // ─────────────────────────────────────────────────────────────────

    /**
     * Backup status, with the restore drill called out because a backup that
     * has never been restored is not yet proven.
     *
     * @return array{label: string, detail: string, tone: string, taken_at: ?string, verified: bool}
     */
    public function backup(): array
    {
        try {
            $record = BackupRecord::query()
                ->where('db_name', $this->project->db_name)
                ->orderByDesc('finished_at')
                ->first();
        } catch (\Throwable) {
            $record = null;
        }

        if (! $record) {
            return [
                'label' => 'Never',
                'detail' => 'No backup on record. Cutover cannot be reversible without one.',
                'tone' => 'danger',
                'taken_at' => null,
                'verified' => false,
            ];
        }

        $verified = $record->status === 'ok'
            && $record->restore_test_status === 'ok'
            && $record->verified_at !== null;

        return [
            'label' => $record->finished_at?->diffForHumans() ?? ucfirst((string) $record->status),
            'detail' => $verified
                ? 'Verified and restore-drilled.'
                : 'Not proven: a backup that has not been restored is not yet a rollback plan.',
            'tone' => $verified ? 'success' : 'danger',
            'taken_at' => $record->finished_at?->toIso8601String(),
            'verified' => $verified,
        ];
    }

    /**
     * Rollback readiness: the plan and how long the window stays open.
     *
     * @return array{ready: bool, label: string, detail: string, expires_at: ?string, procedure: ?string}
     */
    public function rollback(): array
    {
        $plan = $this->plan();
        $backup = $this->backup();

        // Rollback needs BOTH: a recorded plan and a proven backup behind it.
        // The message names whichever is missing, so the user is never told
        // simply "not armed" without knowing what to do about it.
        $ready = $plan !== null && $backup['verified'];

        $detail = match (true) {
            $plan === null && ! $backup['verified'] => 'No cutover plan and no verified backup. Rollback is not yet possible.',
            $plan === null => 'No cutover plan yet, so no rollback plan has been generated.',
            ! $backup['verified'] => 'A rollback plan exists, but the backup behind it is not verified and restore-drilled.',
            default => 'A verified backup exists and a rollback plan is recorded.',
        };

        $label = match (true) {
            $ready => 'Armed',
            $plan === null => 'No plan',
            default => 'Not armed',
        };

        $rollback = $plan && is_array($plan->rollback) ? $plan->rollback : [];

        return [
            'ready' => $ready,
            'label' => $label,
            'detail' => $detail,
            'expires_at' => $plan?->rollback_expiry?->toIso8601String(),
            'procedure' => $rollback['procedure'] ?? null,
        ];
    }

    /**
     * Final sync readiness — whether the data is caught up enough to switch.
     *
     * @return array{ready: bool, label: string, detail: string, tone: string}
     */
    public function finalSync(): array
    {
        $sync = $this->liveSync();

        if ($sync['raw_state'] === 'UNVERIFIED' || $sync['lag_seconds'] === null) {
            return [
                'ready' => false,
                'label' => 'Not verified',
                'detail' => 'Live Sync has not reported, so the final delta cannot be judged.',
                'tone' => 'warning',
            ];
        }

        $lag = $sync['lag_seconds'];
        if ($lag >= ProjectPulse::SYNC_LAG_BLOCKING_SECONDS) {
            return [
                'ready' => false,
                'label' => 'Behind',
                'detail' => 'The last change was applied '.ProjectPulse::for($this->project)->humanDuration($lag).' ago. A final delta now would be large.',
                'tone' => 'danger',
            ];
        }

        if ($lag >= ProjectPulse::SYNC_LAG_WARNING_SECONDS) {
            return [
                'ready' => false,
                'label' => 'Catching up',
                'detail' => 'Last change applied '.ProjectPulse::for($this->project)->humanDuration($lag).' ago.',
                'tone' => 'warning',
            ];
        }

        return [
            'ready' => true,
            'label' => 'Up to date',
            'detail' => 'The last change was applied '.ProjectPulse::for($this->project)->humanDuration($lag).' ago.',
            'tone' => 'success',
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // Section: human approvals
    // ─────────────────────────────────────────────────────────────────

    /**
     * Human approvals required and already recorded (§10).
     *
     * Nothing auto-approves: a gate in APPROVAL_REQUIRED with no row is
     * "awaiting", never "granted".
     *
     * @return list<array{gate: string, label: string, decision: ?string, decided_at: ?string, decided_by: ?string, note: ?string}>
     */
    public function approvals(): array
    {
        $plan = $this->plan();
        $out = [];

        foreach (CutoverCenterService::APPROVAL_REQUIRED as $gate) {
            $approval = $plan ? $this->approvalFor($plan, $gate) : null;

            $out[] = [
                'gate' => $gate,
                'label' => $this->humanise($gate),
                'decision' => $approval['decision'] ?? null,
                'decided_at' => $approval['decided_at'] ?? null,
                'decided_by' => $approval['decided_by'] ?? null,
                'note' => $approval['note'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array{decision: string, decided_at: ?string, decided_by: ?string, note: ?string}|null */
    private function approvalFor(CutoverPlan $plan, string $gate): ?array
    {
        try {
            $approval = CutoverApproval::query()
                ->where('cutover_plan_id', $plan->id)
                ->where('gate', $gate)
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (! $approval) {
            return null;
        }

        $decidedBy = null;
        if ($approval->approved_by) {
            try {
                $decidedBy = User::query()->whereKey($approval->approved_by)->value('name');
            } catch (\Throwable) {
                $decidedBy = null;
            }
        }

        return [
            'decision' => (string) $approval->decision,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'decided_by' => $decidedBy,
            'note' => $approval->note,
        ];
    }

    public function pendingApprovalCount(): int
    {
        return count(array_filter($this->approvals(), fn (array $a): bool => $a['decision'] !== 'approved'));
    }

    // ─────────────────────────────────────────────────────────────────
    // The ordered plan
    // ─────────────────────────────────────────────────────────────────

    /**
     * The ordered cutover steps, with those still blocked by a gate called out.
     *
     * @return list<array{step: int, action: string, label: string, note: string, approval_required: bool, blocked: bool}>
     */
    public function steps(): array
    {
        $plan = $this->plan();
        $raw = ($plan && is_array($plan->steps) && $plan->steps !== [])
            ? array_values($plan->steps)
            : (new CutoverCenterService)->createPlanStepsPreview($this->project);

        $blocked = $this->blockingGates($this->gates()) !== [];

        return array_map(fn (array $step): array => [
            'step' => (int) ($step['step'] ?? 0),
            'action' => (string) ($step['action'] ?? ''),
            'label' => $this->humanise((string) ($step['action'] ?? '')),
            'note' => (string) ($step['note'] ?? ''),
            'approval_required' => (bool) ($step['approval_required'] ?? false),
            'blocked' => $blocked,
        ], $raw);
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    public function plan(): ?CutoverPlan
    {
        try {
            return CutoverPlan::query()
                ->where('project_id', $this->project->id)
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function gateByKey(string $key): ?array
    {
        foreach ($this->gates() as $gate) {
            if ($gate['gate'] === $key) {
                return $gate;
            }
        }

        return null;
    }

    /** Gate state → one of BLOCK / WARN / UNVERIFIED / PASS. */
    private function toProductState(string $rawState): string
    {
        return match ($rawState) {
            'BLOCK' => 'BLOCK',
            'WARN' => 'WARN',
            'PASS', 'NOT_APPLICABLE' => 'PASS',
            default => 'UNVERIFIED',
        };
    }

    private function productLabel(string $rawState): string
    {
        return match ($rawState) {
            'PASS' => 'Pass',
            'NOT_APPLICABLE' => 'Not applicable',
            'BLOCK' => 'Blocked',
            'WARN' => 'Warning',
            default => 'Not verified',
        };
    }

    private function productTone(string $rawState): string
    {
        return match ($rawState) {
            'PASS', 'NOT_APPLICABLE' => 'success',
            'WARN' => 'warning',
            'BLOCK' => 'danger',
            default => 'neutral',
        };
    }

    private function productIcon(string $rawState): string
    {
        return match ($rawState) {
            'PASS', 'NOT_APPLICABLE' => 'heroicon-o-check-circle',
            'WARN' => 'heroicon-o-exclamation-triangle',
            'BLOCK' => 'heroicon-o-no-symbol',
            default => 'heroicon-o-question-mark-circle',
        };
    }

    private function humanise(string $token): string
    {
        return ucfirst(str_replace('_', ' ', $token));
    }
}
