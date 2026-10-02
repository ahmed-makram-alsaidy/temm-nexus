<?php

namespace Tests\Feature\Phase40;

use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use App\Services\Product\ProjectPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * rc.3 hotfix regressions — raw/shape-mismatched pulse data must never
 * fatal the platform's first screens. Both were found live on the
 * operator VPS during rc.2 acceptance:
 *
 *  1. a checkpoint that CARRIES a last_event_at (raw query-builder row:
 *     ->diffInSeconds() on a string) fatals Home/Overview;
 *  2. a blocked run whose `failure` payload is an ARRAY (model cast)
 *     fatals the attention list via mb_substr().
 */
class ProjectPulseCheckpointTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    protected function seedRun(string $status, array $extra = []): MigrationRun
    {
        $this->buildTenantFixture();
        $this->alphaWeb->forceFill(['db_name' => 'alpha_web_db'])->save();

        $source = \App\Models\MigrationSource::create([
            'project_id' => $this->alphaWeb->id, 'type' => 'postgres',
            'display_name' => 'rc3', 'connection' => ['host' => 'localhost'],
            'read_only' => true, 'status' => 'pending',
        ]);
        $analysis = \App\Models\MigrationAnalysis::create([
            'project_id' => $this->alphaWeb->id, 'migration_source_id' => $source->id,
            'run_id' => 'rc3-a', 'status' => 'completed',
        ]);
        $plan = MigrationPlan::create([
            'project_id' => $this->alphaWeb->id, 'migration_analysis_id' => $analysis->id,
            'name' => 'RC3 plan', 'status' => 'ready',
        ]);

        return MigrationRun::create(array_merge([
            'project_id' => $this->alphaWeb->id, 'migration_plan_id' => $plan->id,
            'run_id' => 'rc3-r', 'dry_run' => false, 'mode' => 'rehearsal',
            'status' => $status,
        ], $extra));
    }

    public function test_a_checkpoint_with_last_event_at_does_not_fatal_live_sync(): void
    {
        $run = $this->seedRun('streaming');
        CdcCheckpoint::create([
            'migration_run_id' => $run->id, 'source_type' => 'postgres',
            'target_key' => 'rc3', 'kind' => 'stream',
            'position' => ['lsn' => '0/RC3'], 'signature' => 'rc3',
            'stream_status' => 'streaming',
            'last_event_at' => now()->subSeconds(30),
        ]);

        $pulse = ProjectPulse::for($this->alphaWeb->fresh());

        $lag = $pulse->liveSync()['lagSeconds'];
        $this->assertIsInt($lag);
        $this->assertGreaterThanOrEqual(0, $lag);
        $this->assertStringContainsString('Last synced', $pulse->liveSync()['detail']);
    }

    public function test_a_blocked_run_with_structured_failure_does_not_fatal_attention(): void
    {
        $run = $this->seedRun('failed', [
            'failure' => ['stage' => 'copy', 'error' => 'target unreachable'],
        ]);

        $pulse = ProjectPulse::for($this->alphaWeb->fresh());
        $attention = collect($pulse->blockers());

        $this->assertTrue($attention->count() > 0);
        foreach ($attention as $item) {
            $this->assertIsString($item['detail']);
        }
    }
}
