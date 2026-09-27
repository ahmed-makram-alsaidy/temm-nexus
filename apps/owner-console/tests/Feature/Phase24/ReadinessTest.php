<?php

namespace Tests\Feature\Phase24;

use App\Models\BackupRun;
use App\Models\ReadinessCheck;
use App\Models\ReadinessSnapshot;
use App\Services\ControlPlane\ReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24H — Readiness: machine checks, guarded acks, blockers, history. */
class ReadinessTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_evaluate_runs_machine_checks_and_records_blocks(): void
    {
        $summary = ReadinessService::evaluate($this->projectA);

        // Fresh project: DB unreachable → red; no backup → red; no drill → red.
        $this->assertGreaterThan(0, $summary['counts']['red']);
        $this->assertGreaterThan(0, count($summary['blockers']));
        $this->assertDatabaseHas('readiness_checks', [
            'project_id' => $this->projectA->id, 'check_key' => 'restore_test_age', 'status' => 'red', 'blocks_production' => 1,
        ]);
    }

    public function test_healthy_project_reduces_blockers(): void
    {
        // Simulate fresh backup + passed drill.
        BackupRun::create([
            'project_id' => $this->projectA->id, 'type' => 'database', 'trigger' => 'manual',
            'started_at' => now(), 'finished_at' => now(), 'status' => 'completed',
        ]);
        BackupRun::create([
            'project_id' => $this->projectA->id, 'type' => 'restore_drill', 'trigger' => 'drill',
            'started_at' => now(), 'finished_at' => now(), 'status' => 'drill_passed',
        ]);

        $summary = ReadinessService::evaluate($this->projectA);
        $keys = collect($summary['checks'])->pluck('check_key')->all();
        $this->assertContains('backup_freshness', $keys);
        $backup = collect($summary['checks'])->firstWhere('check_key', 'backup_freshness');
        $this->assertSame('green', $backup['status']);
        $restore = collect($summary['checks'])->firstWhere('check_key', 'restore_test_age');
        $this->assertSame('green', $restore['status']);
    }

    public function test_manual_ack_cannot_override_red_machine_check(): void
    {
        ReadinessService::evaluate($this->projectA); // produces red machine checks

        $red = ReadinessCheck::where('project_id', $this->projectA->id)->where('origin', 'machine')->where('status', 'red')->first();
        $this->assertNotNull($red);

        $this->expectException(HttpException::class);
        ReadinessService::acknowledge($this->projectA, null, $red->check_key, 'I promise it is fine');
    }

    public function test_manual_check_can_be_acknowledged_with_audit(): void
    {
        ReadinessService::addManualCheck($this->projectA, null, [
            'category' => 'Integrations', 'title' => 'Payment provider sandbox verified', 'status' => 'yellow', 'note' => 'pending live keys',
        ]);
        $check = ReadinessCheck::where('project_id', $this->projectA->id)->where('origin', 'manual')->first();

        ReadinessService::acknowledge($this->projectA, null, $check->check_key, 'verified by ops on call');
        $fresh = $check->fresh();
        $this->assertSame($this->admin->id, $fresh->acknowledged_by);
        $this->assertSame('verified by ops on call', $fresh->acknowledgement_note);
        $this->assertDatabaseHas('admin_audit_entries', ['action' => 'READINESS_ACKNOWLEDGED', 'project_id' => $this->projectA->id]);
    }

    public function test_snapshots_keep_history(): void
    {
        ReadinessService::evaluate($this->projectA);
        ReadinessService::snapshot($this->projectA);
        ReadinessService::snapshot($this->projectA);

        $this->assertSame(2, ReadinessSnapshot::where('project_id', $this->projectA->id)->count());
        $snap = ReadinessSnapshot::where('project_id', $this->projectA->id)->latest('id')->first();
        $this->assertArrayHasKey('counts', $snap->summary);
        $this->assertArrayHasKey('blockers', $snap->summary);
    }

    public function test_drift_finding_sets_blocker(): void
    {
        ReadinessService::recordDrift($this->projectA, null, true, 'drop column detected between staging and production');
        $summary = ReadinessService::summary($this->projectA);
        $drift = collect($summary['blockers'])->firstWhere('check_key', 'schema_drift_unresolved');
        $this->assertNotNull($drift, 'dangerous drift must be a production blocker');
    }
}
