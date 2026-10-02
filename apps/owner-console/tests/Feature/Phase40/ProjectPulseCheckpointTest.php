<?php

namespace Tests\Feature\Phase40;

use App\Models\MigrationPlan;
use App\Models\MigrationRun;
use App\Models\Project;
use App\Services\ControlPlane\Migration\Cdc\CdcCheckpoint;
use App\Services\Product\ProjectPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Phase40\Concerns\BuildsTenantFixture;
use Tests\TestCase;

/**
 * rc.3 hotfix regression — a CDC checkpoint that CARRIES a last_event_at
 * fatals Home/Overview (raw query-builder row: ->diffInSeconds() on a
 * string). Found live on the operator VPS during rc.2 acceptance.
 */
class ProjectPulseCheckpointTest extends TestCase
{
    use BuildsTenantFixture, RefreshDatabase;

    public function test_a_checkpoint_with_last_event_at_does_not_fatal_live_sync(): void
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
        $run = MigrationRun::create([
            'project_id' => $this->alphaWeb->id, 'migration_plan_id' => $plan->id,
            'run_id' => 'rc3-r', 'dry_run' => false, 'mode' => 'cdc', 'status' => 'streaming',
        ]);
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

}
