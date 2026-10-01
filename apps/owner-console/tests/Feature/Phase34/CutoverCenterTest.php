<?php

namespace Tests\Feature\Phase34;

use App\Models\BackupRecord;
use App\Models\CutoverApproval;
use App\Models\CutoverEvent;
use App\Models\CutoverPlan;
use App\Models\MigrationRun;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\Cutover\CutoverCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 34 — the Cutover Center: honest gate states (34B), ordered plan
 * (34C), explicit approval gates (34D), rollback plan (34E) and audit
 * (34F). The platform never executes production changes itself (34D).
 */
class CutoverCenterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
        $this->project = Project::create([
            'name' => 'P34', 'slug' => 'p34-'.uniqid(), 'status' => 'active',
            'api_domain' => 'api.p34.test', 'api_version' => 'v1',
            'db_name' => 'p34_db_'.uniqid(),
        ]);
    }

    public function test_preflight_is_honest_without_evidence(): void
    {
        $plan = (new CutoverCenterService)->createPlan($this->project);
        $gates = collect($plan->gates)->keyBy('gate');

        // 34B — no verified backup → BLOCK, never a fake green.
        $this->assertSame('BLOCK', $gates['backup_verified']['state']);
        // No migration run → UNVERIFIED.
        $this->assertSame('UNVERIFIED', $gates['data_rehearsal']['state']);
        // CDC lag is measured live in the window — unknown until then.
        $this->assertSame('UNVERIFIED', $gates['cdc_lag']['state']);
        // Endpoint switch is operator-owned — never claimed as done.
        $this->assertSame('UNVERIFIED', $gates['endpoint_switch']['state']);
        // Only gate states from the honest vocabulary.
        foreach ($plan->gates as $gate) {
            $this->assertContains($gate['state'], CutoverCenterService::GATE_STATES);
        }
    }

    public function test_verified_backup_turns_the_gate_green(): void
    {
        BackupRecord::create([
            'db_name' => $this->project->db_name, 'status' => 'ok', 'size_bytes' => 100,
            'checksum' => hash('sha256', 'x'), 'finished_at' => now(),
            'restore_test_status' => 'ok', 'verified_at' => now(), 'type' => 'full', 'location' => 'local',
        ]);
        $plan = (new CutoverCenterService)->createPlan($this->project);
        $gates = collect($plan->gates)->keyBy('gate');
        $this->assertSame('PASS', $gates['backup_verified']['state']);
    }

    public function test_production_affecting_steps_require_explicit_approval(): void
    {
        $service = new CutoverCenterService;
        $plan = $service->createPlan($this->project);

        // Without approval → refused.
        try {
            $service->recordStep($plan, 'final_delta', ['status' => 'done']);
            $this->fail('final_delta must require an explicit approval (34D)');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Explicit human approval → step can be recorded.
        $service->approve($plan, 'final_delta', 'approved', 'operator on window');
        $service->recordStep($plan, 'final_delta', ['status' => 'done']);
        $this->assertDatabaseHas('cutover_approvals', ['cutover_plan_id' => $plan->id, 'gate' => 'final_delta', 'decision' => 'approved']);
    }

    public function test_approvals_require_a_valid_gate_and_decision(): void
    {
        $service = new CutoverCenterService;
        $plan = $service->createPlan($this->project);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->approve($plan, 'target_health', 'approved'); // not an approval gate
    }

    public function test_plan_includes_ordered_steps_and_rollback(): void
    {
        $plan = (new CutoverCenterService)->createPlan($this->project);

        $steps = $plan->steps;
        $this->assertSame(11, count($steps));
        $this->assertSame('verify_backup', $steps[0]['action']);
        $this->assertSame('complete_or_rollback', $steps[10]['action']);
        // 34D — production-affecting steps are marked approval_required.
        $approvalSteps = array_column(array_filter($steps, fn ($s) => ! empty($s['approval_required'])), 'action');
        $this->assertContains('endpoint_switch', $approvalSteps);
        $this->assertStringContainsStringIgnoringCase('never automatic', $steps[7]['note']);

        // 34E — rollback plan carries the essentials.
        $rollback = $plan->rollback;
        foreach (['backup_reference', 'old_endpoint', 'migration_checkpoint', 'rollback_expiry_days', 'procedure'] as $key) {
            $this->assertArrayHasKey($key, $rollback);
        }
        $this->assertNotNull($plan->rollback_expiry);
    }

    public function test_every_event_is_audited(): void
    {
        $service = new CutoverCenterService;
        $plan = $service->createPlan($this->project);
        $service->approve($plan, 'backup_restore_drill', 'approved');

        $events = $plan->events()->pluck('event')->all();
        $this->assertContains('plan_created', $events);
        $this->assertContains('approval', $events);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'CUTOVER_EVENT']);
        foreach ($plan->events as $event) {
            $this->assertNotNull($event->request_id, '34F — request id recorded');
        }
    }
}
