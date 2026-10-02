<?php

namespace Tests\Feature\Phase40;

use App\Models\BackupRecord;
use App\Models\CutoverApproval;
use App\Models\CutoverPlan;
use App\Models\ReadinessCheck;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use App\Services\Product\CutoverReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * 0.4.0 Phase E (§10) — Cutover readiness.
 *
 * Cutover is the moment a client's production switches, so these tests are
 * deliberately adversarial: they check that the screen refuses to say READY
 * when the evidence is missing, that it always explains WHY it is blocked, and
 * that recording an approval is a real capability rather than a UI affordance.
 */
class CutoverReadinessTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTenantFixture();
        // A db_name is required for the backup gate to have anything to match.
        $this->alphaWeb->forceFill(['db_name' => 'alpha_web_db'])->save();
    }

    // ── Overall readiness is never optimistic ──────────────────────────

    public function test_a_project_with_no_evidence_is_blocked_not_ready(): void
    {
        $overall = CutoverReadiness::for($this->alphaWeb->fresh())->overall();

        $this->assertSame(CutoverReadiness::BLOCKED, $overall['state']);
        $this->assertFalse(CutoverReadiness::for($this->alphaWeb->fresh())->isReady());
    }

    public function test_a_missing_verified_backup_blocks_cutover(): void
    {
        $readiness = CutoverReadiness::for($this->alphaWeb->fresh());

        $backupGate = collect($readiness->gates())->firstWhere('gate', 'backup_verified');

        $this->assertNotNull($backupGate);
        $this->assertSame('BLOCK', $backupGate['product_state']);
        // §10: the user must understand WHY.
        $this->assertStringContainsString('restore drill', $backupGate['evidence']);
    }

    public function test_an_unverified_backup_still_blocks(): void
    {
        // status ok but no restore drill and no verified_at: NOT proven.
        BackupRecord::create([
            'db_name' => 'alpha_web_db',
            'status' => 'ok',
            'type' => 'full',
            'location' => 'local',
            'restore_test_status' => 'not_tested',
            'finished_at' => now(),
        ]);

        $gate = collect(CutoverReadiness::for($this->alphaWeb->fresh())->gates())
            ->firstWhere('gate', 'backup_verified');

        $this->assertSame('BLOCK', $gate['product_state'], 'A backup that was never restored must not pass the gate.');
    }

    public function test_a_verified_and_drilled_backup_passes_the_gate(): void
    {
        BackupRecord::create([
            'db_name' => 'alpha_web_db',
            'status' => 'ok',
            'type' => 'full',
            'location' => 'local',
            'restore_test_status' => 'ok',
            'verified_at' => now(),
            'finished_at' => now(),
        ]);

        $gate = collect(CutoverReadiness::for($this->alphaWeb->fresh())->gates())
            ->firstWhere('gate', 'backup_verified');

        $this->assertSame('PASS', $gate['product_state']);
        $this->assertSame('success', $gate['tone']);
    }

    public function test_a_gate_with_no_evidence_is_never_green(): void
    {
        $readiness = CutoverReadiness::for($this->alphaWeb->fresh());

        foreach ($readiness->gates() as $gate) {
            if ($gate['state'] === 'UNVERIFIED') {
                $this->assertSame('neutral', $gate['tone'], "Gate {$gate['gate']} presented UNVERIFIED as a positive state.");
                $this->assertSame('Not verified', $gate['label']);
            }
        }
    }

    // ── The three product states ───────────────────────────────────────

    public function test_overall_reports_one_of_the_three_product_states(): void
    {
        $state = CutoverReadiness::for($this->alphaWeb->fresh())->overall()['state'];

        $this->assertContains($state, [
            CutoverReadiness::READY,
            CutoverReadiness::WARNING,
            CutoverReadiness::BLOCKED,
        ]);
    }

    public function test_overall_is_warning_when_nothing_blocks_but_items_are_unverified(): void
    {
        // Satisfy the only hard blocker; the rest stay UNVERIFIED.
        BackupRecord::create([
            'db_name' => 'alpha_web_db',
            'status' => 'ok',
            'type' => 'full',
            'location' => 'local',
            'restore_test_status' => 'ok',
            'verified_at' => now(),
            'finished_at' => now(),
        ]);

        $overall = CutoverReadiness::for($this->alphaWeb->fresh())->overall();

        $this->assertSame(CutoverReadiness::WARNING, $overall['state']);
        $this->assertStringContainsString('need', $overall['detail']);
    }

    // ── Blocking issues carry a reason ─────────────────────────────────

    public function test_every_blocking_issue_states_a_reason(): void
    {
        $issues = CutoverReadiness::for($this->alphaWeb->fresh())->blockingIssues();

        $this->assertNotEmpty($issues);
        foreach ($issues as $issue) {
            $this->assertNotSame('', $issue['detail'], "Issue '{$issue['title']}' gave no reason.");
            $this->assertContains($issue['severity'], ['danger', 'warning']);
            $this->assertNotSame('', $issue['gate']);
        }
    }

    public function test_a_failed_readiness_check_makes_reconciliation_fail(): void
    {
        ReadinessCheck::create([
            'project_id' => $this->alphaWeb->id,
            'category' => 'data',
            'check_key' => 'checksum',
            'title' => 'Checksums differ',
            'status' => 'failed',
            'blocks_production' => true,
        ]);

        $validation = CutoverReadiness::for($this->alphaWeb->fresh())->validation();

        $this->assertSame('Failed', $validation['label']);
        $this->assertSame('danger', $validation['tone']);
        $this->assertSame(1, $validation['failed']);
    }

    // ── The named readiness dimensions ─────────────────────────────────

    public function test_all_six_named_dimensions_are_reported(): void
    {
        $r = CutoverReadiness::for($this->alphaWeb->fresh());

        // §10's list. Each must carry a label and an explanation — `overall()`
        // uses 'sentence'/'reason' rather than 'detail', so assert on the
        // presence of *an* explanation rather than one specific key name.
        foreach ([
            'overall' => $r->overall(),
            'liveSync' => $r->liveSync(),
            'validation' => $r->validation(),
            'backup' => $r->backup(),
            'rollback' => $r->rollback(),
            'finalSync' => $r->finalSync(),
        ] as $name => $dimension) {
            $this->assertArrayHasKey('label', $dimension, "{$name} has no label.");
            $this->assertNotSame('', (string) $dimension['label'], "{$name} has an empty label.");

            $explanation = $dimension['detail'] ?? $dimension['sentence'] ?? $dimension['reason'] ?? null;
            $this->assertNotNull($explanation, "{$name} explains nothing to the user.");
            $this->assertNotSame('', (string) $explanation, "{$name} has an empty explanation.");
        }
    }

    public function test_live_sync_detail_uses_product_language_not_internal_tokens(): void
    {
        $sync = CutoverReadiness::for($this->alphaWeb->fresh())->liveSync();

        foreach (['CDC', 'LSN', 'checkpoint'] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $sync['detail']);
            $this->assertStringNotContainsStringIgnoringCase($internal, $sync['label']);
        }
    }

    public function test_final_sync_is_not_ready_when_live_sync_has_never_reported(): void
    {
        $final = CutoverReadiness::for($this->alphaWeb->fresh())->finalSync();

        $this->assertFalse($final['ready']);
        $this->assertNotSame('success', $final['tone']);
    }

    public function test_rollback_is_not_armed_without_a_verified_backup(): void
    {
        $rollback = CutoverReadiness::for($this->alphaWeb->fresh())->rollback();

        $this->assertFalse($rollback['ready']);
        // The user is told what is missing, not merely "not armed".
        $this->assertStringContainsString('verified', $rollback['detail']);
    }

    public function test_rollback_becomes_armed_once_plan_and_verified_backup_exist(): void
    {
        BackupRecord::create([
            'db_name' => 'alpha_web_db',
            'status' => 'ok',
            'type' => 'full',
            'location' => 'local',
            'restore_test_status' => 'ok',
            'verified_at' => now(),
            'finished_at' => now(),
        ]);

        (new CutoverCenterService)->createPlan($this->alphaWeb->fresh());

        $rollback = CutoverReadiness::for($this->alphaWeb->fresh())->rollback();

        $this->assertTrue($rollback['ready']);
        $this->assertNotNull($rollback['expires_at']);
        // The rollback PROCEDURE must be recorded, not just a flag.
        $this->assertNotSame('', (string) $rollback['procedure']);
    }

    // ── Approvals ──────────────────────────────────────────────────────

    public function test_approvals_start_awaiting_and_nothing_auto_approves(): void
    {
        $approvals = CutoverReadiness::for($this->alphaWeb->fresh())->approvals();

        $this->assertCount(count(CutoverCenterService::APPROVAL_REQUIRED), $approvals);
        foreach ($approvals as $approval) {
            $this->assertNull($approval['decision'], "Gate {$approval['gate']} auto-approved.");
        }
        $this->assertSame(count($approvals), CutoverReadiness::for($this->alphaWeb->fresh())->pendingApprovalCount());
    }

    public function test_recording_an_approval_is_reflected_and_audited(): void
    {
        $service = new CutoverCenterService;
        $plan = $service->createPlan($this->alphaWeb->fresh());

        $service->approve($plan, 'backup_restore_drill', 'approved', 'Drill reviewed by ops.');

        $approvals = CutoverReadiness::for($this->alphaWeb->fresh())->approvals();
        $drill = collect($approvals)->firstWhere('gate', 'backup_restore_drill');

        $this->assertSame('approved', $drill['decision']);
        $this->assertSame('Drill reviewed by ops.', $drill['note']);
        $this->assertSame('approved', CutoverApproval::query()->where('cutover_plan_id', $plan->id)->value('decision'));
    }

    public function test_a_gate_outside_the_approval_matrix_cannot_be_approved(): void
    {
        $plan = (new CutoverCenterService)->createPlan($this->alphaWeb->fresh());

        $this->expectException(HttpException::class);

        (new CutoverCenterService)->approve($plan, 'target_health', 'approved');
    }

    public function test_an_invalid_decision_is_rejected(): void
    {
        $plan = (new CutoverCenterService)->createPlan($this->alphaWeb->fresh());

        $this->expectException(HttpException::class);

        (new CutoverCenterService)->approve($plan, 'final_delta', 'maybe');
    }

    // ── The plan ───────────────────────────────────────────────────────

    public function test_the_ordered_plan_is_previewable_without_persisting_anything(): void
    {
        $before = CutoverPlan::query()->count();

        $steps = (new CutoverCenterService)->createPlanStepsPreview($this->alphaWeb->fresh());

        $this->assertNotEmpty($steps);
        $this->assertSame($before, CutoverPlan::query()->count(), 'Previewing the plan created a plan.');
        $this->assertSame(1, $steps[0]['step']);
    }

    public function test_the_plan_marks_the_operator_owned_steps_as_requiring_approval(): void
    {
        $steps = CutoverReadiness::for($this->alphaWeb->fresh())->steps();
        $gated = collect($steps)->where('approval_required', true)->pluck('action')->all();

        // These are the steps the platform must never take on its own.
        $this->assertContains('endpoint_switch', $gated);
        $this->assertContains('final_delta', $gated);
        $this->assertContains('source_freeze', $gated);
    }

    public function test_the_endpoint_switch_step_is_explicitly_operator_owned(): void
    {
        $steps = collect(CutoverReadiness::for($this->alphaWeb->fresh())->steps());
        $switch = $steps->firstWhere('action', 'endpoint_switch');

        $this->assertNotNull($switch);
        $this->assertStringContainsStringIgnoringCase('never automatic', $switch['note']);
    }

    // ── HTTP surface ───────────────────────────────────────────────────

    public function test_a_project_viewer_can_open_the_cutover_screen(): void
    {
        $this->actingAs($this->alphaViewer)
            ->get('/admin/projects/'.$this->alphaBooking->id.'/cutover')
            ->assertOk()
            ->assertSee('Cutover');
    }

    public function test_the_cutover_screen_explains_why_it_is_blocked(): void
    {
        $this->actingAs($this->alphaOwner)
            ->get('/admin/projects/'.$this->alphaWeb->id.'/cutover')
            ->assertOk()
            ->assertSee('Why you cannot proceed')
            // The reason, not just the state.
            ->assertSee('restore drill', false);
    }

    public function test_a_viewer_without_the_approve_capability_is_told_so(): void
    {
        $this->actingAs($this->alphaViewer)
            ->get('/admin/projects/'.$this->alphaBooking->id.'/cutover')
            ->assertOk()
            ->assertSee('cutover.approve', false);
    }

    public function test_an_approver_is_not_shown_the_capability_notice(): void
    {
        // alphaOwner holds cutover.approve in their workspace.
        $this->assertTrue(
            Access::for($this->alphaOwner)
                ->allows(Capability::CUTOVER_APPROVE, 'project', null, $this->alphaWeb),
        );

        $this->actingAs($this->alphaOwner)
            ->get('/admin/projects/'.$this->alphaWeb->id.'/cutover')
            ->assertOk()
            ->assertDontSee('does not include', false);
    }

    public function test_a_tenant_cannot_reach_another_tenants_cutover_screen(): void
    {
        // Reach is enforced by the resource resolution, so this must not be 200.
        $response = $this->actingAs($this->betaOwner)
            ->get('/admin/projects/'.$this->alphaWeb->id.'/cutover');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_unauthenticated_access_is_refused(): void
    {
        $this->get('/admin/projects/'.$this->alphaWeb->id.'/cutover')->assertRedirect();
    }

    public function test_the_platform_never_performs_the_endpoint_switch_itself(): void
    {
        // Regression guard on the Phase 34 safety posture: the service is a
        // control room, not an executor. If an executor method ever appears,
        // this test is the tripwire.
        $methods = get_class_methods(CutoverCenterService::class);

        foreach (['executeCutover', 'switchEndpoint', 'performCutover', 'applyCutover'] as $forbidden) {
            $this->assertNotContains($forbidden, $methods, "CutoverCenterService grew an executor method: {$forbidden}");
        }
    }
}
