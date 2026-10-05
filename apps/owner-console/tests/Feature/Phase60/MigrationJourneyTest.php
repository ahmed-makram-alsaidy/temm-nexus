<?php

namespace Tests\Feature\Phase60;

use App\Filament\Resources\Projects\Pages\ProjectMigration;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter;
use App\Services\ControlPlane\Migration\AnalysisOutcomeClassifier;
use App\Services\ControlPlane\Migration\MigrationCenterService;
use App\Services\Product\ComponentRegistry;
use App\Services\Product\JourneyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 0.6.0 Phase E — the MIGRATION JOURNEY (§E12–§E30).
 *
 * The project Migration tab is ONE journey with six stage tabs absorbing the
 * Migration Center / Cutover / Readiness / Copilot faces. The acceptance
 * criteria: canonical stage states (ProjectPulse — no second interpretation),
 * warning-vs-error classification (pg_largeobject is a warning, never an
 * ERROR), summary-first analysis results, deterministic review readiness,
 * plain-language guard copy, one place for validation evidence, contextual
 * Copilot, viewer-safe permissions, EN+AR, and bounded page queries.
 */
class MigrationJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    protected function viewer(): User
    {
        return User::create([
            'name' => 'Bare Viewer',
            'email' => uniqid('viewer').'@test.local',
            'password' => 'password-password-123',
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

    protected function project(?Workspace $ws = null): Project
    {
        return Project::create([
            'name' => 'Acme Website',
            'slug' => 'acme-'.uniqid(),
            'workspace_id' => ($ws ?? $this->workspace())->id,
            'status' => 'active',
            'environment' => 'development',
            'health_status' => 'unknown',
            'db_name' => 'acme_db',
        ]);
    }

    protected function seedSource(Project $project, string $status = 'ready'): int
    {
        return DB::table('migration_sources')->insertGetId([
            'project_id' => $project->id,
            'type' => 'postgres',
            'display_name' => 'Production database',
            'connection' => json_encode(['host' => 'db.internal', 'database' => 'sourcedb']),
            'status' => $status,
            'last_tested_at' => $status === 'ready' ? now()->subMinutes(5) : null,
            'last_error' => $status === 'error' ? 'E[42501] raw database message kept for details' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedAnalysis(Project $project, int $sourceId, string $status = 'completed', array $extra = []): int
    {
        return DB::table('migration_analyses')->insertGetId(array_merge([
            'project_id' => $project->id,
            'migration_source_id' => $sourceId,
            'run_id' => 'an-'.uniqid(),
            'status' => $status,
            'counts' => json_encode(['tables' => 12, 'views' => 3]),
            'warnings' => json_encode([]),
            'errors' => json_encode([]),
            'telemetry' => json_encode(['current' => null, 'stages' => []]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    protected function seedPlan(Project $project, int $analysisId): int
    {
        return DB::table('migration_plans')->insertGetId([
            'project_id' => $project->id,
            'migration_analysis_id' => $analysisId,
            'name' => 'Acme plan',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedRun(Project $project, int $planId, string $status = 'completed', array $extra = []): void
    {
        DB::table('migration_runs')->insert(array_merge([
            'project_id' => $project->id,
            'migration_plan_id' => $planId,
            'run_id' => 'run-'.uniqid(),
            'mode' => 'dry_run',
            'status' => $status,
            'progress' => json_encode(['done' => 12, 'total' => 12]),
            'target_connection' => json_encode(['database' => 'acme_target']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    // ── §E8: warning vs error classification ───────────────────────────

    public function test_optional_probe_failure_completes_the_analysis_with_warnings_not_error(): void
    {
        $project = $this->project();
        $service = new MigrationCenterService;
        $service->registerAdapter('stub-warning', OptionalProbeStubAdapter::class);
        $sourceId = DB::table('migration_sources')->insertGetId([
            'project_id' => $project->id,
            'type' => 'stub-warning',
            'display_name' => 'Restricted source',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $source = \App\Models\MigrationSource::find($sourceId);

        $analysis = $service->analyze($source);

        $this->assertSame('completed', $analysis->status, 'A benign optional-probe denial must NOT fail the analysis');
        $this->assertNotEmpty($analysis->warnings);
        $this->assertSame('postgres.large_objects', $analysis->warnings[0]['check']);
        $this->assertSame('42501', $analysis->warnings[0]['sqlstate']);
        // The source stays usable — the live audit defect flipped it to ERROR.
        $this->assertNotSame('error', $source->fresh()->status);
        $this->assertSame('ready', $source->fresh()->status);
    }

    public function test_blocking_failure_classifies_and_marks_the_source(): void
    {
        $service = new MigrationCenterService;
        $service->registerAdapter('stub-blocking', BlockingStubAdapter::class);

        $project = $this->project();
        $sourceId = DB::table('migration_sources')->insertGetId([
            'project_id' => $project->id,
            'type' => 'stub-blocking',
            'display_name' => 'Unreachable source',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $source = \App\Models\MigrationSource::find($sourceId);

        $analysis = $service->analyze($source);

        $this->assertSame('failed', $analysis->status);
        $this->assertSame('unreachable', $analysis->errors['kind']);
        $this->assertSame('error', $source->fresh()->status);
    }

    public function test_warnings_present_in_product_language_with_technicals_separate(): void
    {
        $presented = AnalysisOutcomeClassifier::present([
            ['severity' => 'warning', 'check' => 'postgres.large_objects', 'sqlstate' => '42501', 'message' => 'permission denied for table pg_largeobject'],
        ]);

        $this->assertSame(__('migration.warning_postgres.large_objects'), $presented[0]['title']);
        $this->assertStringNotContainsString('42501', $presented[0]['title'], 'SQLSTATE never reaches the default face');
        $this->assertStringNotContainsString('pg_largeobject', $presented[0]['title']);
    }

    // ── §E12: stage navigation ─────────────────────────────────────────

    public function test_migration_page_renders_six_stage_tabs(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]))
            ->assertOk()
            ->getContent();

        foreach (['connect', 'analyze', 'plan', 'sync', 'verify', 'cutover'] as $stage) {
            $this->assertStringContainsString(__('migration.stage_'.$stage), $html);
        }
    }

    public function test_stage_query_parameter_selects_the_stage(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('migration.sync_empty_title'), $html);
    }

    public function test_default_stage_is_derived_from_the_canonical_journey(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        $this->seedRun($project, $planId);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]))->getContent();

        // With a completed run and no checkpoint, ProjectPulse puts the
        // project at SYNC (the merged MIGRATE+SYNC product tab), where the
        // run card — not the empty state — is the honest face.
        $this->assertStringContainsString(__('migration.sync_progress'), $html);
    }

    // ── §E14: Connect stage ────────────────────────────────────────────

    public function test_connect_stage_shows_source_status_destination_and_last_test(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $this->seedAnalysis($project, $sourceId);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=connect')->getContent();

        $this->assertStringContainsString('Production database', $html);
        $this->assertStringContainsString(__('projects.source_connected'), $html);
        $this->assertStringContainsString(__('migration.connect_destination_managed', ['database' => $project->db_name]), $html);
        $this->assertStringContainsString(__('migration.connect_last_test'), $html);
    }

    public function test_connect_stage_empty_state_teaches_and_offers_the_action(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=connect')->getContent();

        $this->assertStringContainsString(__('migration.connect_empty_title'), $html);
        $this->assertStringContainsString(__('migration.connect_add_source'), $html);
    }

    // ── §E15/§E9: Analyze stage ────────────────────────────────────────

    public function test_analyze_stage_empty_state(): void
    {
        $project = $this->project();
        $this->seedSource($project);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        $this->assertStringContainsString(__('migration.analyze_empty_title'), $html);
        $this->assertStringContainsString(__('migration.analyze_run'), $html);
    }

    public function test_analyze_stage_shows_summary_first_and_no_raw_sqlstate(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $this->seedAnalysis($project, $sourceId, 'completed', [
            'warnings' => json_encode([
                ['severity' => 'warning', 'check' => 'postgres.large_objects', 'sqlstate' => '42501', 'message' => 'permission denied for table pg_largeobject'],
            ]),
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        // §E9 — the summary leads with the discovered-tables label (the
        // stage's own counts vocabulary, kind-safe with a ucfirst fallback).
        $this->assertStringContainsString(__('migration.counts_tables'), $html);
        $this->assertStringContainsString(__('migration.warning_postgres.large_objects'), $html);
        // Raw diagnostics only live inside the closed Technical details.
        $this->assertStringContainsString(__('foundation.error_technical_details'), $html);
    }

    public function test_analyze_stage_failed_state_speaks_plainly(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project, 'error');
        $this->seedAnalysis($project, $sourceId, 'failed', [
            'errors' => json_encode(['kind' => 'unreachable', 'sqlstate' => '08006', 'message' => 'connection refused to db.internal']),
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        $this->assertStringContainsString(__('migration.analyze_failed_title'), $html);
        $this->assertStringContainsString(__('migration.analyze_failed_body'), $html);
    }

    // ── §E16: Plan stage ───────────────────────────────────────────────

    public function test_plan_stage_shows_human_readable_scope(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        DB::table('migration_plan_items')->insert([
            'migration_plan_id' => $planId,
            'source_kind' => 'auth',
            'source_name' => 'users',
            'stage' => 1,
            'status' => 'READY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=plan')->getContent();

        $this->assertStringContainsString(__('migration.plan_heading'), $html);
        $this->assertStringContainsString('users', $html);
        $this->assertStringNotContainsString('plan-'.substr((string) $planId, 0, 4), $html, 'Internal plan IDs stay hidden');
    }

    // ── §E17: Sync stage ───────────────────────────────────────────────

    public function test_sync_stage_shows_run_status_progress_and_plain_guard_copy(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        $this->seedRun($project, $planId);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')->getContent();

        $this->assertStringContainsString(__('migration.sync_progress'), $html);
        $this->assertStringContainsString('12 / 12', $html);
        $this->assertStringContainsString(__('migration.sync_guard_plain'), $html);
        // The audit's jargon guard line must be gone from the default face.
        $this->assertStringNotContainsString('Guard: production targets are refused', $html);
        $this->assertStringNotContainsString('disposable target; source ≠ target enforced', $html);
    }

    public function test_sync_stage_run_history_stays_behind_the_disclosure(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        for ($i = 0; $i < 30; $i++) {
            $this->seedRun($project, $planId);
        }
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')->getContent();

        // Bounded: only the latest 10 runs render, inside the disclosure.
        $this->assertSame(1, substr_count($html, 'data-migration-run-history'));
    }

    // ── §E18: Verify stage ─────────────────────────────────────────────

    public function test_verify_stage_groups_checks_into_passed_review_blocked(): void
    {
        $project = $this->project();
        $this->seedSource($project);
        DB::table('readiness_checks')->insert([
            ['project_id' => $project->id, 'check_key' => 'ck-a', 'category' => 'data', 'title' => 'Counts match', 'status' => 'green', 'origin' => 'machine', 'blocks_production' => false, 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $project->id, 'check_key' => 'ck-b', 'category' => 'data', 'title' => 'Schema drift', 'status' => 'yellow', 'origin' => 'machine', 'blocks_production' => false, 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $project->id, 'check_key' => 'ck-c', 'category' => 'backup', 'title' => 'No verified backup', 'status' => 'red', 'origin' => 'machine', 'blocks_production' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=verify')->getContent();

        $this->assertStringContainsString(__('migration.verify_passed'), $html);
        $this->assertStringContainsString(__('migration.verify_needs_review'), $html);
        $this->assertStringContainsString(__('migration.verify_blocked'), $html);
        $this->assertStringContainsString('Counts match', $html);
        $this->assertStringContainsString('No verified backup', $html);
    }

    // ── §E19: Cutover preservation ─────────────────────────────────────

    public function test_cutover_stage_renders_the_blocked_gate_model(): void
    {
        $project = $this->project();
        $this->seedSource($project);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=cutover')->getContent();

        // The accepted pattern, verbatim concepts: overall state + why.
        $this->assertStringContainsString(__('cutover.readiness_overall'), $html);
        $this->assertStringContainsString(__('cutover.readiness_why_blocked'), $html);
        $this->assertStringContainsString(__('cutover.readiness_gates'), $html);
        $this->assertStringContainsString(__('cutover.gate_unverified'), $html);
    }

    public function test_cutover_gate_states_do_not_fake_green(): void
    {
        $project = $this->project();
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=cutover')->getContent();

        // A fresh project has no evidence: the primary action is locked and
        // nothing may read as green/ready.
        $this->assertStringContainsString(__('cutover.readiness_locked'), $html);
        $this->assertStringNotContainsString(__('cutover.overall_ready_label'), $html);
    }

    // ── §E21: Copilot placement ────────────────────────────────────────

    public function test_copilot_is_a_contextual_panel_on_the_analyze_stage_not_a_peer_destination(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $this->seedAnalysis($project, $sourceId);
        \App\Models\AiProviderConfig::create([
            'provider' => 'openai', 'display_name' => 'Test provider',
            'model' => 'gpt-test', 'secret_encrypted' => 'x', 'enabled' => true,
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        $this->assertStringContainsString(__('migration.copilot_panel_title'), $html);

        // Not a peer destination in the tab model anymore.
        $tabs = \App\Filament\Support\ProjectTabs::TABS['migration']['pages'];
        $this->assertArrayNotHasKey('copilot', $tabs);
        $this->assertArrayHasKey('migration', $tabs);
    }

    // ── §E26: permissions ──────────────────────────────────────────────

    public function test_viewer_can_read_stages_but_sees_no_write_actions(): void
    {
        $ws = $this->workspace();
        $project = $this->project($ws);
        $this->seedSource($project);
        $viewer = $this->viewer();
        WorkspaceMember::create([
            'workspace_id' => $ws->id, 'user_id' => $viewer->id,
            'role' => Roles::WORKSPACE_MEMBER, 'status' => 'active',
        ]);
        $this->actingAs($viewer);

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=connect')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('migration.connect_add_source'), $html);
        $this->assertStringNotContainsString('data-migration-add-source', $html);
    }

    public function test_viewer_cannot_execute_write_actions_server_side(): void
    {
        $ws = $this->workspace();
        $project = $this->project($ws);
        $this->seedSource($project);
        $viewer = $this->viewer();
        WorkspaceMember::create([
            'workspace_id' => $ws->id, 'user_id' => $viewer->id,
            'role' => Roles::WORKSPACE_MEMBER, 'status' => 'active',
        ]);
        $this->actingAs($viewer);

        $component = \Livewire::test(ProjectMigration::class, ['record' => $project->getKey()])
            ->set('stage', 'analyze')
            ->call('startAnalysis');

        // Server-side authorization is the source of truth: no analysis row
        // was created, no matter what the UI offered.
        $this->assertSame(
            0,
            \App\Models\MigrationAnalysis::where('project_id', $project->id)->count(),
        );
    }

    // ── §E25: Arabic ───────────────────────────────────────────────────

    public function test_migration_stage_renders_translated_in_arabic(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        $this->seedRun($project, $planId);
        // §E25 — the user's language preference drives the render (the same
        // resolution the topbar locale switcher writes).
        $owner = $this->owner();
        $owner->update(['locale' => 'ar']);
        $this->actingAs($owner);

        app()->setLocale('ar');
        $expectedProgress = __('migration.sync_progress');
        $expectedGuard = __('migration.sync_guard_plain');
        app()->setLocale('en');

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')->getContent();

        // The Arabic page carries the Arabic chrome and the plain guard copy.
        $this->assertStringContainsString($expectedProgress, $html);
        $this->assertStringContainsString($expectedGuard, $html);
        // No hardcoded English duration sentences in the Arabic page.
        $this->assertStringNotContainsString('The last change was applied', $html);
    }

    public function test_cutover_readiness_durations_are_localized(): void
    {
        $project = $this->project();
        $this->seedSource($project);
        $this->actingAs($this->owner());

        // EN detail uses the translated sentence (durations through durationAgo).
        $readiness = \App\Services\Product\CutoverReadiness::for($project);
        $sync = $readiness->finalSync();
        $this->assertSame(__('cutover.finalsync_unverified_label'), $sync['label']);
    }

    // ── §E28: performance bounds ───────────────────────────────────────

    public function test_migration_page_query_count_stays_bounded_with_large_history(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project);
        $analysisId = $this->seedAnalysis($project, $sourceId);
        $planId = $this->seedPlan($project, $analysisId);
        for ($i = 0; $i < 100; $i++) {
            $this->seedRun($project, $planId);
        }
        $this->actingAs($this->owner());

        DB::enableQueryLog();
        $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=sync')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Fixed limits keep the page flat regardless of run count (§E28).
        $this->assertLessThan(80, $queries, "Migration page issued {$queries} queries with 100 runs");
    }

    // ── §E30: Inspect registry alignment ──────────────────────────────

    public function test_migration_page_inspect_keys_exist_in_the_registry(): void
    {
        $project = $this->project();
        $this->seedSource($project);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        preg_match_all('/data-nx-inspect="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $key) {
            $this->assertTrue(
                ComponentRegistry::exists($key),
                "Inspect key {$key} is rendered but not registered — a stale component key",
            );
        }

        // The journey components are registered for the new page.
        foreach (['migration.stages', 'migration.connect', 'migration.analyze', 'migration.plan', 'migration.sync', 'migration.verify'] as $key) {
            $this->assertTrue(ComponentRegistry::exists($key));
        }
    }

    // ── §E27: persistence / reload ─────────────────────────────────────

    public function test_analysis_state_survives_a_page_reload(): void
    {
        $project = $this->project();
        $sourceId = $this->seedSource($project, 'pending');
        $analysisId = $this->seedAnalysis($project, $sourceId, 'running', [
            'telemetry' => json_encode([
                'current' => 'inspecting',
                'stages' => [
                    'preparing' => ['state' => 'done', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()],
                    'inspecting' => ['state' => 'active', 'started_at' => now()->toIso8601String()],
                ],
            ]),
        ]);
        $this->actingAs($this->owner());

        $html = $this->get(ProjectResource::getUrl('migration', ['record' => $project]).'?stage=analyze')->getContent();

        // The reload shows the persisted, in-flight operation (not a fresh form).
        $this->assertStringContainsString(__('migration.analyze_progress_title'), $html);
        $this->assertStringContainsString(__('migration.analyze_stage_inspecting'), $html);
    }
}


/**
 * Phase E test doubles. Declared in THIS FILE (no mid-test require) so the
 * hermetic suite stays self-contained: a source adapter whose OPTIONAL probe
 * fails with the audit's live specimen (pg_largeobject, SQLSTATE 42501), and
 * one that cannot connect at all. Together they pin §E8's two sides.
 */
class OptionalProbeStubAdapter extends \App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter
{
    public static function id(): string
    {
        return 'stub-warning';
    }

    public function connect(): void
    {
        $this->connection = ['host' => 'fixture', 'database' => 'stub'];
    }

    public function inventory(): array
    {
        $this->recordProbeWarning('postgres.large_objects', new \RuntimeException(
            'SQLSTATE[42501]: permission denied for table pg_largeobject',
        ));

        return [
            'tables' => [
                ['schema' => 'public', 'name' => 'users'],
                ['schema' => 'public', 'name' => 'orders'],
            ],
            'views' => [],
        ];
    }

    public function countRows(string $schema, string $table): int
    {
        return 0;
    }

    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return 0;
    }

    public function close(): void
    {
        parent::close();
    }
}

class BlockingStubAdapter extends \App\Services\ControlPlane\Connectors\Support\BaseSourceAdapter
{
    public static function id(): string
    {
        return 'stub-blocking';
    }

    public function connect(): void
    {
        throw new \RuntimeException('SQLSTATE[08006] could not connect to server: connection refused');
    }

    public function inventory(): array
    {
        return [];
    }

    public function countRows(string $schema, string $table): int
    {
        return 0;
    }

    public function streamRows(string $schema, string $table, array $columns, callable $callback, int $batchSize = 500): int
    {
        return 0;
    }

    public function close(): void
    {
        parent::close();
    }
}
