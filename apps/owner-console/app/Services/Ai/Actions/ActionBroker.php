<?php

namespace App\Services\Ai\Actions;

use App\Models\ActionPlan;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\Ai\AiContext;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Support\Facades\Log;

/**
 * 0.4.0 Phase J — the broker between "a model proposed an action" and
 * "the platform performed it".
 *
 * THE CONTRACT (J.4–J.12)
 *  - PROPOSE validates the action against the registry, the acting user's
 *    capability, and the argument schema; then persists an immutable PLAN.
 *    Nothing else happens.
 *  - APPROVE binds a human to the plan row and executes in one atomic step:
 *    the status transition `pending -> approved` is a single conditional
 *    UPDATE, so a double click (or two operators) cannot execute twice.
 *  - Execution RE-AUTHORIZES the approver at execution time, RE-CHECKS the
 *    state fingerprint (a stale plan refuses), runs the registered handler,
 *    then runs the registered verifier, and reports APPLIED and VERIFIED
 *    separately and honestly.
 *  - Every step is audited with the AI_ACTION_* actions. Arguments are
 *    redacted before they touch the ledger. Failures are recorded with a
 *    safe message — never a stack trace, never credentials.
 *
 * THE MODEL IS NEVER IN THIS LOOP except at the first step: it can propose,
 * nothing more. Approval comes only from `approveAndExecute`, which is only
 * reachable from the page a human clicks.
 */
final class ActionBroker
{
    public function __construct(
        private readonly AiContext $context,
    ) {}

    // ── Propose ────────────────────────────────────────────────────────

    /**
     * Validate and persist a plan. Returns ['ok', 'plan'|'denied', ...].
     *
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, plan?: ActionPlan, denied?: string}
     */
    public function propose(string $action, array $arguments = []): array
    {
        // 1 — registered actions only.
        if (! ActionRegistry::exists($action)) {
            return ['ok' => false, 'denied' => 'unknown_action'];
        }

        $definition = ActionRegistry::definition($action);

        // 2 — risk gate: HIGH and PROHIBITED are not proposable in Phase J.
        if (! in_array($definition['risk'], ActionRegistry::PROPOSABLE_RISKS, true)) {
            return ['ok' => false, 'denied' => 'risk_not_proposable'];
        }

        // 3 — the context must carry the object the action belongs to.
        if ($definition['scope']->value !== $this->context->scope->value) {
            return ['ok' => false, 'denied' => 'wrong_scope'];
        }
        if ($definition['scope'] === \App\Services\Ai\Scope::PROJECT && $this->context->projectTarget() === null) {
            return ['ok' => false, 'denied' => 'missing_context'];
        }

        // 4 — the ACTING user holds the capability NOW (not the conversation's
        // opening state — `Access` re-reads membership per call).
        if (! $this->context->allows($definition['capability'])) {
            return ['ok' => false, 'denied' => 'missing_capability'];
        }

        // 5 — arguments strictly against the declared schema.
        $clean = ActionRegistry::sanitizeArguments($action, $arguments);
        if ($clean === null) {
            return ['ok' => false, 'denied' => 'bad_arguments'];
        }

        // 6 — state fingerprint, where the action has mutable state.
        $project = $this->context->projectTarget();
        $fingerprint = ActionRegistry::fingerprintFor($action, $project, $clean);

        $plan = ActionPlan::query()->create([
            'user_id' => $this->context->access->user()->getKey(),
            'action' => $action,
            'scope' => $definition['scope']->value,
            'workspace_id' => $this->context->workspace?->getKey(),
            'project_id' => $project?->getKey(),
            'arguments' => $clean,
            'intent' => ActionRegistry::intentFor($action, $project, $clean),
            'affected' => ActionRegistry::affectedFor($action, $project, $clean),
            'risk' => $definition['risk'],
            'fingerprint' => $fingerprint,
            'verification' => $definition['description'],
            'status' => ActionPlan::STATUS_PENDING,
            'expires_at' => now()->addMinutes(ActionPlan::TTL_MINUTES),
        ]);

        $this->audit('AI_ACTION_PROPOSED', $plan, ['proposer' => 'model']);

        return ['ok' => true, 'plan' => $plan];
    }

    // ── Approve + execute ──────────────────────────────────────────────

    /**
     * Approve a plan AND run it. Everything below is server-side; the client
     * supplies only the plan id.
     *
     * @return array{ok: bool, plan: ActionPlan, error?: string}
     */
    public function approveAndExecute(string $planId, User $approver): array
    {
        $plan = ActionPlan::query()->whereKey($planId)->first();

        if ($plan === null) {
            return ['ok' => false, 'plan' => new ActionPlan, 'error' => 'unknown_plan'];
        }

        // EXPIRED plans cannot execute (J.5).
        if ($plan->isExpired()) {
            $plan->update(['status' => ActionPlan::STATUS_EXPIRED]);
            $this->audit('AI_ACTION_REJECTED', $plan, ['reason' => 'expired']);

            return ['ok' => false, 'plan' => $plan, 'error' => 'expired'];
        }

        // ATOMIC CLAIM: only one caller can move pending -> approved. A double
        // click loses here and returns the existing outcome (J.8).
        $claimed = ActionPlan::query()
            ->whereKey($plan->getKey())
            ->where('status', ActionPlan::STATUS_PENDING)
            ->update([
                'status' => ActionPlan::STATUS_APPROVED,
                'approved_by' => $approver->getKey(),
                'approved_at' => now(),
            ]);

        if ($claimed === 0) {
            $plan->refresh();

            return ['ok' => false, 'plan' => $plan, 'error' => 'not_pending'];
        }

        $this->audit('AI_ACTION_APPROVED', $plan, []);

        $plan->refresh();

        // EXECUTION-TIME AUTHORISATION (J.6): the conversation having been
        // allowed is not authority. The approver must hold, NOW, both the
        // AI approval capability AND the action's own capability, on THIS
        // scope. A revoked user is refused here.
        $access = Access::for($approver);
        $definition = ActionRegistry::definition($plan->action);

        $authorized = match ($plan->scope) {
            'project' => $plan->project_id !== null
                && $access->allows(Capability::AI_APPROVE_ACTIONS, 'project', null, $plan->project_id)
                && $access->allows($definition['capability'], 'project', null, $plan->project_id),
            'workspace' => $plan->workspace_id !== null
                && $access->allows(Capability::AI_APPROVE_ACTIONS, 'workspace', $plan->workspace_id)
                && $access->allows($definition['capability'], 'workspace', $plan->workspace_id),
            default => $access->allows(Capability::AI_APPROVE_ACTIONS, 'platform')
                && $access->allows($definition['capability'], 'platform'),
        };

        if (! $authorized) {
            $plan->update(['status' => ActionPlan::STATUS_REJECTED, 'result' => ['error' => 'not_authorized']]);
            $this->audit('AI_ACTION_REJECTED', $plan, ['reason' => 'not_authorized']);

            return ['ok' => false, 'plan' => $plan, 'error' => 'not_authorized'];
        }

        // STALE PLAN DEFENCE (J.7): the world the plan describes must still
        // exist. A checkpoint that vanished (or changed status) refuses.
        $project = $plan->project_id !== null ? \App\Models\Project::find($plan->project_id) : null;
        if ($project !== null) {
            $current = ActionRegistry::fingerprintFor($plan->action, $project, $plan->arguments ?? []);
            if ($current !== null && $current !== $plan->fingerprint) {
                $plan->update(['status' => ActionPlan::STATUS_STALE, 'result' => [
                    'fingerprint_at_plan' => $plan->fingerprint,
                    'fingerprint_now' => $current,
                ]]);
                $this->audit('AI_ACTION_REJECTED', $plan, ['reason' => 'stale']);

                return ['ok' => false, 'plan' => $plan, 'error' => 'stale'];
            }
        }

        return $this->run($plan, $project);
    }

    /**
     * Execute + verify a CLAIMED plan. Separated so tests can drive the
     * execution stage deterministically after claiming.
     *
     * @return array{ok: bool, plan: ActionPlan}
     */
    public function run(ActionPlan $plan, ?\App\Models\Project $project): array
    {
        $plan->increment('attempts');

        try {
            $result = ActionRegistry::execute($plan->action, $project, $plan->arguments ?? []);
            $result = is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            // J.12 — a safe, first-line failure message only. No stack trace,
            // no connection details, no provider-visible content.
            $safe = mb_substr($e->getMessage(), 0, 200);
            $plan->update([
                'status' => ActionPlan::STATUS_FAILED,
                'executed_at' => now(),
                'result' => ['ok' => false, 'error' => $safe, 'stage' => 'execute'],
            ]);
            $this->audit('AI_ACTION_APPLIED', $plan, ['outcome' => 'failed', 'stage' => 'execute']);
            Log::warning('AI action execution failed', [
                'plan_id' => $plan->getKey(),
                'action' => $plan->action,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'plan' => $plan, 'error' => 'execution_failed'];
        }

        $plan->update([
            'executed_at' => now(),
            'result' => ['ok' => true, 'stage' => 'execute'] + $result,
        ]);

        // VERIFY (J.11) — a successful return code is not verification.
        try {
            $verification = ActionRegistry::verify($plan->action, $project, $plan->arguments ?? [], $result);
            $verification = is_array($verification) ? $verification : ['verified' => false];
        } catch (\Throwable) {
            $verification = ['verified' => false, 'detail' => 'Verification itself failed.'];
        }

        $plan->update([
            'status' => ($verification['verified'] ?? false)
                ? ActionPlan::STATUS_VERIFIED
                : ActionPlan::STATUS_VERIFICATION_FAILED,
            'result' => ['ok' => true, 'stage' => 'verified'] + $result + ['verification' => $verification],
        ]);

        $this->audit('AI_ACTION_APPLIED', $plan, ['outcome' => 'applied']);
        $this->audit(
            ($verification['verified'] ?? false) ? 'AI_ACTION_VERIFIED' : 'AI_ACTION_APPLIED',
            $plan,
            [
                'outcome' => ($verification['verified'] ?? false) ? 'verified' : 'verification_failed',
                'verification' => (string) ($verification['detail'] ?? ''),
            ]
        );

        return ['ok' => true, 'plan' => $plan];
    }

    // ── Reject ─────────────────────────────────────────────────────────

    /**
     * A human declined. Only a PENDING plan can be declined, and cancelling
     * never executes anything.
     */
    public function reject(string $planId, User $user): array
    {
        $plan = ActionPlan::query()->whereKey($planId)->first();

        if ($plan === null || $plan->status !== ActionPlan::STATUS_PENDING) {
            return ['ok' => false];
        }

        $declined = ActionPlan::query()
            ->whereKey($plan->getKey())
            ->where('status', ActionPlan::STATUS_PENDING)
            ->update(['status' => ActionPlan::STATUS_REJECTED]);

        if ($declined === 0) {
            return ['ok' => false];
        }

        $plan->refresh();
        $this->audit('AI_ACTION_REJECTED', $plan, ['reason' => 'declined_by_user']);

        return ['ok' => true, 'plan' => $plan];
    }

    // ── Audit ──────────────────────────────────────────────────────────

    private function audit(string $action, ActionPlan $plan, array $extra): void
    {
        try {
            AdminAudit::record(
                $action,
                \App\Models\Project::find($plan->project_id),
                'ai_action_plan',
                $plan->getKey(),
                array_merge([
                    'kind' => 'action',
                    'action' => $plan->action,
                    'risk' => $plan->risk,
                    'scope' => $plan->scope,
                    'workspace_id' => $plan->workspace_id,
                    'project_id' => $plan->project_id,
                    'user_id' => $plan->user_id,
                    'approved_by' => $plan->approved_by,
                    'arguments' => ActionRegistry::redactArguments($plan->arguments ?? []),
                    'actor_kind' => 'ai',
                ], $extra),
            );
        } catch (\Throwable $e) {
            Log::warning('AI action audit failed', ['plan_id' => $plan->getKey(), 'action' => $action]);
        }
    }
}
