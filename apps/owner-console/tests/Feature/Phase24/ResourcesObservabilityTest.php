<?php

namespace Tests\Feature\Phase24;

use App\Models\BackupRun;
use App\Models\CostEntry;
use App\Models\ResourceMetricSample;
use App\Services\ControlPlane\ResourceObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase24\Concerns\BuildsEngineFixture;
use Tests\TestCase;

/** Phase 24G — Resource observability: honest metrics, thresholds, cost estimates. */
class ResourcesObservabilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsEngineFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBase();
        $this->actingAs($this->admin);
    }

    public function test_collect_records_only_available_metrics(): void
    {
        $collected = ResourceObservabilityService::collect($this->projectA);

        // No real project DB for fixture-a → db metrics must be ABSENT, not fake.
        $this->assertArrayNotHasKey('db_size_bytes', $collected);
        $this->assertArrayNotHasKey('db_connections', $collected);

        // Backup bytes always computable (sum over recorded runs).
        $this->assertArrayHasKey('backup_bytes', $collected);
        $this->assertSame(0, $collected['backup_bytes']);

        // Node-level metrics, when the host exposes them, are recorded.
        $rows = ResourceMetricSample::where('project_id', $this->projectA->id)->get();
        foreach ($rows as $row) {
            $this->assertContains($row->attribution, ['project', 'node']);
            if (str_starts_with($row->metric, 'node_')) {
                $this->assertSame('node', $row->attribution, 'node metrics must be honestly attributed');
            }
        }
    }

    public function test_backup_bytes_sum_is_real(): void
    {
        BackupRun::create([
            'project_id' => $this->projectA->id, 'type' => 'database', 'trigger' => 'manual',
            'started_at' => now(), 'finished_at' => now(), 'status' => 'completed', 'size_bytes' => 2048,
        ]);
        BackupRun::create([
            'project_id' => $this->projectB->id, 'type' => 'database', 'trigger' => 'manual',
            'started_at' => now(), 'finished_at' => now(), 'status' => 'completed', 'size_bytes' => 999999,
        ]);

        $collected = ResourceObservabilityService::collect($this->projectA);
        $this->assertSame(2048, $collected['backup_bytes'], 'must sum ONLY this project runs (isolation)');
    }

    public function test_current_reports_unavailable_not_fabricated(): void
    {
        $rows = ResourceObservabilityService::current($this->projectA);
        $this->assertSame('unavailable', $rows['db_size_bytes']['status']);
        $this->assertNull($rows['db_size_bytes']['value']);
    }

    public function test_thresholds_trigger_warnings(): void
    {
        ResourceMetricSample::create([
            'project_id' => $this->projectA->id, 'metric' => 'backup_bytes',
            'value' => 5000, 'unit' => 'bytes', 'attribution' => 'project', 'sampled_at' => now(),
        ]);
        ResourceObservabilityService::setThreshold($this->projectA, 'backup_bytes', 1000, 10000);

        $rows = ResourceObservabilityService::current($this->projectA);
        $this->assertSame('warning', $rows['backup_bytes']['status']);

        ResourceMetricSample::create([
            'project_id' => $this->projectA->id, 'metric' => 'backup_bytes',
            'value' => 20000, 'unit' => 'bytes', 'attribution' => 'project', 'sampled_at' => now(),
        ]);
        $rows = ResourceObservabilityService::current($this->projectA);
        $this->assertSame('critical', $rows['backup_bytes']['status']);

        $warnings = ResourceObservabilityService::warnings($this->projectA);
        $this->assertCount(1, $warnings);
        $this->assertSame('backup_bytes', $warnings[0]['metric']);
    }

    public function test_cost_allocation_is_labeled_estimate(): void
    {
        $entry = ResourceObservabilityService::recordCost($this->projectA, [
            'name' => 'shared VPS', 'monthly_cost' => 600, 'currency' => 'EGP', 'allocation' => 'equal',
        ]);

        $estimate = ResourceObservabilityService::allocationEstimate($entry);
        $this->assertStringContainsStringIgnoringCase('estimate', $estimate['label']);
        $this->assertArrayHasKey('basis', $estimate);
        $this->assertGreaterThan(0, $estimate['estimate']);
    }

    public function test_unknown_metric_threshold_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        ResourceObservabilityService::setThreshold($this->projectA, 'made_up_metric', 1, 2);
    }

    public function test_trends_windows(): void
    {
        foreach ([24, 96, 400] as $hoursAgo) {
            ResourceMetricSample::create([
                'project_id' => $this->projectA->id, 'metric' => 'storage_bytes',
                'value' => $hoursAgo, 'unit' => 'bytes', 'attribution' => 'project',
                'sampled_at' => now()->subHours($hoursAgo),
            ]);
        }
        $this->assertCount(2, ResourceObservabilityService::trend($this->projectA, 'storage_bytes', '7d'));
        $this->assertCount(3, ResourceObservabilityService::trend($this->projectA, 'storage_bytes', '30d'));
    }
}
