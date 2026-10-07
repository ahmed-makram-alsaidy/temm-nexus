<?php

namespace Tests\Feature\Phase61;

use App\Filament\Resources\Projects\Pages\ProjectMigration;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MigrationRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ControlPlane\EnvironmentContext;
use App\Services\ControlPlane\Migration\MigrationRunManager;
use App\Services\Product\JourneyState;
use App\Services\Product\ProjectPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 0.6.1 — the Sync state is canonical: it never masquerades as an operation.
 *
 * Production evidence (v0.6.0): a Supabase source whose wizard Review said
 * "Live Sync — Not supported by this connector" completed a 350/350 DRY run,
 * and the Migration → Sync page then showed "Sync — In progress" with a LIVE
 * SYNC panel reading "Starting / Live Sync has not started." — a fake running
 * state for an operation this connector can never perform, plus a missing
 * real-transfer action for the TEMM-managed destination.
 *
 * The five regressions pinned here:
 *  1. unsupported Live Sync renders "Not supported", never "Starting";
 *  2. a completed dry run never fabricates a running Live Sync state;
 *  3. the Sync tab's merged MIGRATE+SYNC state is canonical and deterministic;
 *  4. the TEMM-managed destination exposes the real-transfer action (mode
 *     `real`, non-disposable, managed target — the documented GUARD 1/2/3
 *     semantics preserved);
 *  5. no unsafe transfer mode becomes available by mistake.
 */
class SyncStateCanonicalTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ─────────────────────────────────────────────────────────

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => \App\Services\Access\Roles::PLATFORM_OWNER,
        ]);
    }

    protected function workspace(): Workspace
    {
        return Workspace::create([
            'name' => 'Alpha',
            'slug' => 'alpha-'.uniqid(),
            'kind' => 'client',
            'status' => 'active',
        ]);
    }

    protected function project(array $extra = []): Project
    {
        return Project::create(array_merge([
            'name' => 'Wasla',
            'slug' => 'wasla-'.uniqid(),
            'workspace_id' => $this->workspace()->id,
            'status' => 'active',
            'environment' => 'development',
            'health_status' => 'unknown',
            'db_name' => 'wasla_db',
        ], $extra));
    }

    protected function seedSource(Project $project, string $type = 'supabase'): int
    {
        return DB::table('migration_sources')->insertGetId([
            'project_id' => $project->id,
            'type' => $type,
            'display_name' => 'Wasla source',
            'connection' => json_encode(['host' => '127.0.0.1', 'port' => 1, 'database' => 'sourcedb']),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedAnalysis(Project $project, int $sourceId): int
    {
        return DB::table('migration_analyses')->insertGetId([
            'project_id' => $project->id,
            'migration_source_id' => $sourceId,
            'run_id' => 'an-'.uniqid(),
            'status' => 'completed',
            'counts' => json_encode(['tables' => 54, 'views' => 3]),
            'warnings' => json_encode([]),
            'errors' => json_encode([]),
            'telemetry' => json_encode(['current' => null, 'stages' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedPlan(Project $project, int $analysisId): int
    {
        return DB::table('migration_plans')->insertGetId([
            'project_id' => $project->id,
            'migration_analysis_id' => $analysisId,
            'name' => 'plan-'.uniqid(),
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Seed a run with BOTH mode columns explicit, exactly as start() writes them. */
    protected function seedRun(Project $project, int $planId, string $mode, string $status = 'completed'): int
    {
        return DB::table('migration_runs')->insertGetId([
            'project_id' => $project->id,
            'migration_plan_id' => $planId,
            'run_id' => 'run-'.uniqid(),
            'dry_run' => $mode === 'dry_run',
            'mode' => $mode,
            'status' => $status,
            'progress' => json_encode(['done' => 350, 'total' => 350]),
            'target_connection' => json_encode(['database' => 'wasla_db']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedCheckpoint(int $runId, string $streamStatus): void
    {
        DB::table('cdc_checkpoints')->insert([
            'migration_run_id' => $runId,
            'source_type' => 'postgres',
            'target_key' => 't',
            'kind' => 'checkpoint',
            'position' => json_encode(['lsn' => '0/1']),
            'signature' => str_repeat('a', 64),
            'stream_status' => $streamStatus,
            'last_event_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** source → analysis → plan → run, the minimal completed-run chain. */
    protected function seedJourney(Project $project, string $sourceType, string $runMode): int
    {
        $sourceId = $this->seedSource($project, $sourceType);
        $planId = $this->seedPlan($project, $this->seedAnalysis($project, $sourceId));

        return $this->seedRun($project, $planId, $runMode);
    }

    // ── 1. Unsupported Live Sync says so — never "Starting" ─────────────

    public function test_unsupported_live_sync_renders_not_supported_not_starting(): void
    {
        // Supabase declares no change_capture — the Review's exact verdict.
        $project = $this->project();
        $this->seedJourney($project, 'supabase', 'dry_run');
        $this->actingAs($this->owner());

        $pulse = ProjectPulse::for($project->fresh());

        $this->assertSame(
            JourneyState::NOT_STARTED,
            $pulse->stageState(\App\Services\Product\JourneyStage::SYNC),
            'A connector that cannot stream must never sit in IN_PROGRESS',
        );

        $liveSync = $pulse->liveSync();
        $this->assertSame(__('projects.sync_unsupported'), $liveSync['label']);
        $this->assertSame(__('projects.stage_sync_unsupported'), $liveSync['detail']);
        $this->assertNotSame(__('projects.sync_starting'), $liveSync['label']);

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('projects.sync_unsupported'), $html);
        $this->assertStringNotContainsString(__('projects.sync_starting'), $html);
    }

    // ── 2. A completed dry run fabricates nothing ────────────────────────

    public function test_completed_dry_run_does_not_create_a_fake_running_live_sync(): void
    {
        // Postgres DECLARES change_capture, so this pins the second gate:
        // without a checkpoint AND without a writing run, SYNC stays put —
        // a dry run writes nothing and cannot hand off to Live Sync.
        $project = $this->project();
        $this->seedJourney($project, 'postgres', 'dry_run');
        $this->actingAs($this->owner());

        $pulse = ProjectPulse::for($project->fresh());

        $this->assertSame(JourneyState::NOT_STARTED, $pulse->stageState(\App\Services\Product\JourneyStage::SYNC));
        $this->assertSame(__('projects.sync_not_running'), $pulse->liveSync()['label']);
        $this->assertSame(__('projects.stage_sync_none'), $pulse->liveSync()['detail']);

        // The product stage follows MIGRATE (COMPLETE): the subheading state
        // and the Sync tab can no longer read "In progress".
        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('projects.sync_starting'), $html);
        $this->assertStringContainsString('nx-journey-tab--complete', $html, 'The merged Sync tab shows the completed run, not a fake operation');
    }

    // ── 3. The Sync tab state is canonical and deterministic ─────────────

    public function test_sync_tab_state_is_canonical_and_deterministic(): void
    {
        // (a) Supported connector + completed REHEARSAL (a real transfer into
        // a disposable target): "Starting" is the DESIGNED next-step state.
        $rehearsed = $this->project();
        $runId = $this->seedJourney($rehearsed, 'postgres', 'rehearsal');
        $pulse = ProjectPulse::for($rehearsed->fresh());
        $this->assertSame(JourneyState::IN_PROGRESS, $pulse->stageState(\App\Services\Product\JourneyStage::SYNC));
        $this->assertSame(__('projects.sync_starting'), $pulse->liveSync()['label']);

        $this->actingAs($this->owner());
        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $rehearsed]).'?stage=sync')->getContent();
        $this->assertStringContainsString('nx-journey-tab--in_progress', $html);

        // (b) Failed stream telemetry BLOCKED outranks everything (severity
        // merge of MIGRATE COMPLETE + SYNC BLOCKED) — documented, deterministic.
        $blocked = $this->project();
        $blockedRunId = $this->seedJourney($blocked, 'postgres', 'rehearsal');
        $this->seedCheckpoint($blockedRunId, 'failed');
        $pulse = ProjectPulse::for($blocked->fresh());
        $this->assertSame(JourneyState::BLOCKED, $pulse->stageState(\App\Services\Product\JourneyStage::SYNC));

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $blocked]).'?stage=sync')->getContent();
        $this->assertStringContainsString('nx-journey-tab--blocked', $html);

        // (c) Streaming telemetry with a fresh event reads COMPLETE — the
        // checkpoint branch is untouched by the 0.6.1 change.
        $streaming = $this->project();
        $streamRunId = $this->seedJourney($streaming, 'postgres', 'rehearsal');
        $this->seedCheckpoint($streamRunId, 'streaming');
        $pulse = ProjectPulse::for($streaming->fresh());
        $this->assertSame(JourneyState::COMPLETE, $pulse->stageState(\App\Services\Product\JourneyStage::SYNC));
    }

    // ── 4. The managed destination's real-transfer action exists ─────────

    public function test_managed_destination_exposes_and_starts_the_real_transfer(): void
    {
        $project = $this->project(['db_name' => 'wasla_db', 'db_host' => '127.0.0.1', 'db_port' => 1]);
        $this->seedJourney($project, 'postgres', 'dry_run');
        $this->actingAs($this->owner());

        $component = \Livewire::test(ProjectMigration::class, ['record' => $project->getKey()])
            ->call('openRunForm');

        // The option is PRESENTED for the TEMM-managed destination…
        $component->assertSeeHtml('value="real"');

        // …and starting it runs the domain's real mode against the MANAGED
        // target, non-disposable, no reset. The unreachable target (port 1,
        // a closed socket by construction) fails the run closed — no 422, no
        // guard bypass, and nothing is written anywhere.
        $component->set('runMode', 'real')->call('startRun');

        $run = MigrationRun::query()->where('project_id', $project->id)->latest('id')->first();
        $this->assertNotNull($run, 'The real-transfer run was created');
        $this->assertSame('real', $run->mode);
        $this->assertFalse((bool) $run->dry_run);
        $this->assertFalse((bool) $run->target_disposable, 'The managed destination is the real destination — never flagged disposable');
        $this->assertStringContainsString('wasla_db', (string) $run->target_connection, 'A real run targets the TEMM-managed database');
        $this->assertFalse((bool) ($run->progress['reset'] ?? false), 'A real transfer never carries the destructive reset flag');
        $this->assertSame('failed', $run->status, 'The run fails closed against an unreachable target instead of faking success');
    }

    // ── 5. No unsafe transfer mode becomes available by mistake ──────────

    public function test_no_unsafe_transfer_mode_becomes_available_by_mistake(): void
    {
        // (a) Where the platform's own GUARD 1 would refuse every run (active
        // production environment), the real-transfer option is not offered.
        $project = $this->project();
        $this->seedJourney($project, 'postgres', 'dry_run');
        \App\Services\ControlPlane\EnvironmentService::ensureDefaults($project);
        $production = DB::table('project_environments')->where('project_id', $project->id)->where('type', 'production')->first();
        DB::table('project_environments')->where('id', $production->id)->update(['status' => 'active']);
        Session::put(EnvironmentContext::sessionKey($project), $production->id);
        $this->actingAs($this->owner());

        \Livewire::test(ProjectMigration::class, ['record' => $project->getKey()])
            ->call('openRunForm')
            ->assertDontSeeHtml('value="real"');

        // (b) The enforcing guard itself is intact: a production target env
        // type is refused for EVERY mode — including `real` — at the run
        // manager, fail-closed, exactly as in 0.6.0.
        $plan = \App\Models\MigrationPlan::where('project_id', $project->id)->orderByDesc('id')->first();
        $this->expectException(HttpException::class);
        (new MigrationRunManager)->start($plan, [
            'mode' => 'real',
            'target' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 'wasla_db'],
            'target_disposable' => false,
            'target_environment_type' => 'production',
        ]);
    }
}
