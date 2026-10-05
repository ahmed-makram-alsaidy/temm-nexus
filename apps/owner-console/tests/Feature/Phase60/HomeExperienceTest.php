<?php

namespace Tests\Feature\Phase60;

use App\Filament\Pages\Dashboard;
use App\Models\AdminAuditEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use App\Services\ControlPlane\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 0.6.0 Phase C — HOME as a command center.
 *
 * The acceptance criteria, in the mission's own terms: state before
 * statistics, one primary action, max 3 attention items with a SPECIFIC
 * reason, deterministic continue-where-you-left-off (hidden when there is
 * nothing real to resume), ≤ 5 compact project rows, humanized activity,
 * a quiet system line, zero-metric cards removed, role-aware density,
 * graceful degradation, and no raw internal vocabulary anywhere.
 */
class HomeExperienceTest extends TestCase
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

    protected function project(string $name, ?Workspace $workspace = null, array $extra = []): Project
    {
        return Project::create(array_merge([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)).'-'.uniqid(),
            'workspace_id' => $workspace?->id,
            'status' => 'active',
            'environment' => 'development',
            'health_status' => 'unknown',
        ], $extra));
    }

    // ── C9: no-project Home ─────────────────────────────────────────────

    public function test_no_project_home_shows_one_action_into_the_wizard(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin')->assertOk()->getContent();
        $wizard = \App\Filament\Pages\NewProjectWizard::getUrl();

        $this->assertStringContainsString(__('home.empty_title'), $html);
        $this->assertStringContainsString('href="'.$wizard.'"', $html, 'The empty Home primary action must lead to the guided wizard');
        $this->assertStringContainsString(__('home.cta_connect_first'), $html);
        $this->assertStringContainsString(__('home.learn_how'), $html);
    }

    public function test_no_project_home_has_no_metric_grid(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString('nx-grid--stats', $html, 'The KPI card grid must be gone (§C5)');
        $this->assertStringNotContainsString(__('home.summary_active_migrations'), $html);
    }

    // ── C1: one primary action / state sentence ─────────────────────────

    public function test_populated_home_leads_with_state_and_a_single_primary_action(): void
    {
        $this->actingAs($this->owner());
        $this->project('Solo Project');

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString(__('home.state_all_clear'), $html);
        // Exactly one hero primary button.
        $this->assertSame(1, substr_count($html, 'nx-hero__actions'), 'One hero action block');
    }

    public function test_attention_state_sentence_and_primary_review_action(): void
    {
        $this->actingAs($this->owner());

        $blocked = $this->project('Blocked Site', extra: ['health_status' => 'unhealthy']);

        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString('1 project needs attention', $html);
        $this->assertStringContainsString(__('home.cta_review_issues'), $html);
        // The review action follows the problem's own canonical URL
        // (ProjectPulse assigns it; here the missing-backup warning has no
        // deeper page, so it lands on the project overview).
        $this->assertStringContainsString(
            'href="'.\App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $blocked]).'"',
            $html,
        );
    }

    // ── C2: needs attention ─────────────────────────────────────────────

    public function test_attention_items_carry_a_specific_reason_not_a_state_word(): void
    {
        $this->actingAs($this->owner());
        $this->project('Unhealthy Site', extra: ['health_status' => 'unhealthy']);

        $html = $this->get('/admin')->getContent();

        // The reason text is the problem's own title from the domain pulse —
        // and the SECTION label "Needs attention" never appears as the item body.
        $this->assertStringContainsString('No backup recorded', $html);
        $this->assertStringContainsString(__('home.needs_attention'), $html);
        $this->assertStringContainsString('nx-attention__item', $html);
    }

    public function test_attention_is_capped_at_three_with_view_all(): void
    {
        $this->actingAs($this->owner());

        for ($i = 1; $i <= 5; $i++) {
            $this->project("Attention Site {$i}", extra: ['health_status' => 'unhealthy']);
        }

        $html = $this->get('/admin')->getContent();

        // Each danger row renders "nx-attention__item nx-attention__item--danger";
        // counting the base class followed by the modifier bounds it per row.
        $this->assertSame(3, substr_count($html, 'nx-attention__item nx-attention__item--'), 'More than 3 attention items rendered (cap is 3)');
        $this->assertStringContainsString(__('home.needs_attention'), $html);
    }

    public function test_attention_ordering_blocked_before_warning(): void
    {
        $this->actingAs($this->owner());

        // A non-blocking warning check puts the whole project into
        // NEEDS_ATTENTION without blocking it.
        $warning = $this->project('Warning Site');
        DB::table('readiness_checks')->insert([
            'project_id' => $warning->id,
            'category' => 'Database',
            'check_key' => 'db.maintenance_window',
            'title' => 'Maintenance window not scheduled',
            'status' => 'warning',
            'blocks_production' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $blocked = $this->project('Blocked Site', extra: ['health_status' => 'unhealthy']); // → blocked

        $items = \App\Services\Product\PlatformPulse::for(
            \App\Filament\Support\PlatformAccess::current()->access()
        )->projectsNeedingAttention();

        $this->assertGreaterThanOrEqual(2, $items->count());
        $this->assertSame(
            'Blocked Site',
            $items->first()['project']->name,
            'BLOCKED must sort ahead of NEEDS_ATTENTION',
        );
    }

    public function test_attention_empty_shows_a_quiet_all_clear_line(): void
    {
        $this->actingAs($this->owner());
        $this->project('Calm Site');

        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString(__('home.all_clear_line'), $html);
        $this->assertStringNotContainsString('nx-attention__item', $html, 'All-clear must not render attention cards');
    }

    // ── C3: continue where you left off ─────────────────────────────────

    public function test_continue_section_hidden_without_meaningful_activity(): void
    {
        $this->actingAs($this->owner());
        $this->project('Brand New Site'); // created, no events

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString(__('home.cta_continue'), $html, 'Nothing to resume — the section must hide');
    }

    public function test_continue_section_resumes_from_recent_real_activity(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Resumable Site');

        AdminAudit::record('MIGRATION_ANALYSIS_RUN', $project, 'migration_analysis', 1);

        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString(__('home.cta_continue'), $html);
        $this->assertStringContainsString('Resumable Site', $html);
        $this->assertStringContainsString(__('home.projects_title'), $html);
    }

    public function test_continue_prefers_an_active_migration_run(): void
    {
        $this->actingAs($this->owner());
        $idle = $this->project('Idle Site');
        $busy = $this->project('Busy Site');

        AdminAudit::record('MIGRATION_ANALYSIS_RUN', $idle, 'migration_analysis', 1);

        $sourceId = DB::table('migration_sources')->insertGetId([
            'project_id' => $busy->id,
            'type' => 'postgres',
            'display_name' => 'Busy source',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $analysisId = DB::table('migration_analyses')->insertGetId([
            'project_id' => $busy->id,
            'migration_source_id' => $sourceId,
            'run_id' => 'an-'.uniqid(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planId = DB::table('migration_plans')->insertGetId([
            'project_id' => $busy->id,
            'migration_analysis_id' => $analysisId,
            'name' => 'Busy plan',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migration_runs')->insert([
            'project_id' => $busy->id,
            'migration_plan_id' => $planId,
            'run_id' => 'run-'.uniqid(),
            'mode' => 'dry_run',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $target = \App\Services\Product\PlatformPulse::for(
            \App\Filament\Support\PlatformAccess::current()->access()
        )->continueTarget();

        $this->assertNotNull($target);
        $this->assertSame('Busy Site', $target['project']->name, 'An active run beats recent activity');
        $this->assertStringContainsString('stage=sync', (string) $target['url'], 'Resume lands on the Migration journey Sync stage');
    }

    // ── C4: project summary rows ────────────────────────────────────────

    public function test_project_rows_capped_at_five(): void
    {
        $this->actingAs($this->owner());

        for ($i = 1; $i <= 8; $i++) {
            $this->project("Cap Site {$i}");
        }

        $html = $this->get('/admin')->getContent();

        $this->assertSame(5, substr_count($html, 'nx-list__name'), 'Home shows at most 5 project rows');
        $this->assertStringContainsString(__('home.projects_view_all'), $html);
    }

    public function test_project_rows_hide_infrastructure_fields(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Exposed Fields', extra: [
            'db_name' => 'exposed_fields_db',
            'api_domain' => 'api.exposed.example.com',
        ]);

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString('exposed_fields_db', $html, 'DB names never appear on Home (§C4)');
        $this->assertStringNotContainsString('api.exposed.example.com', $html, 'API domains never appear on Home');
        $this->assertStringNotContainsString($project->slug, $html, 'Slugs never appear on Home');
    }

    // ── C5: zero metrics removed ────────────────────────────────────────

    public function test_zero_metric_cards_are_gone(): void
    {
        $this->actingAs($this->owner());
        $this->project('Metricless Site');

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString('nx-stat-card', $html, 'The KPI stat cards must not render on Home');
        $this->assertStringNotContainsString(__('home.summary_transfers_running'), $html);
    }

    // ── C6: activity humanization ───────────────────────────────────────

    public function test_activity_is_humanized_with_context(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Human Site');

        AdminAudit::record('WIZARD_SOURCE_CONNECTED', $project, 'migration_source', 1);

        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString('Connected a source', $html);
        $this->assertStringNotContainsString('WIZARD_SOURCE_CONNECTED', $html, 'Raw audit verbs must not render (§C6)');
        $this->assertStringContainsString(__('home.activity_view_all'), $html);
    }

    public function test_activity_section_hidden_without_audit_capability(): void
    {
        $this->actingAs($this->viewer());

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString(__('home.recent_activity'), $html, 'Viewers without AUDIT_VIEW get no activity section');
    }

    // ── C7: system status ───────────────────────────────────────────────

    public function test_system_line_hidden_when_infrastructure_is_unknown(): void
    {
        $this->actingAs($this->owner()); // owner has INFRASTRUCTURE_VIEW
        $this->project('Quiet System Site');

        $html = $this->get('/admin')->getContent();

        // No infrastructure registered → Home stays silent instead of
        // claiming health it does not know (§C15).
        $this->assertStringNotContainsString(__('home.system_normal'), $html);
    }

    public function test_degraded_system_is_promoted_into_attention(): void
    {
        $this->actingAs($this->owner());
        $this->project('Degraded System Site');

        // Register a degraded infrastructure service (stored state, not a probe).
        $node = \App\Models\InfrastructureNode::create([
            'name' => 'db-1',
            'hostname' => 'db-1.internal',
            'roles' => ['database'],
            'environment' => 'production',
            'status' => 'healthy',
            'enabled' => true,
        ]);
        \App\Models\InfrastructureService::create([
            'key' => 'postgres',
            'label' => 'PostgreSQL',
            'node_id' => $node->id,
            'status' => 'degraded',
            'version' => '17',
        ]);

        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString(__('home.system_attention_title'), $html, 'Degraded systems become attention items (§C7)');
    }

    public function test_system_line_hidden_without_infrastructure_capability(): void
    {
        $this->actingAs($this->viewer());

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString(__('home.system_normal'), $html);
    }

    // ── C10: role-aware Home ────────────────────────────────────────────

    public function test_viewer_gets_no_write_calls_to_action(): void
    {
        // A read-only workspace viewer over their own project.
        $viewer = User::create([
            'name' => 'Workspace Viewer',
            'email' => uniqid('wsviewer').'@test.local',
            'password' => 'password-password-123',
        ]);
        $workspace = Workspace::create([
            'name' => 'Viewer Client',
            'slug' => 'viewer-client-'.uniqid(),
            'kind' => 'client',
            'status' => 'active',
        ]);
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $viewer->id,
            'role' => Roles::VIEWER,
            'status' => 'active',
        ]);
        $this->project('Viewer Site', $workspace);

        $this->actingAs($viewer);

        $html = $this->get('/admin')->assertOk()->getContent();
        $wizard = \App\Filament\Pages\NewProjectWizard::getUrl();

        $this->assertStringNotContainsString('href="'.$wizard.'"', $html, 'A viewer without create capability never sees the create CTA');
        $this->assertStringContainsString('Viewer Site', $html, 'The viewer sees their own project');
    }

    // ── C12/C14: language and consistency ───────────────────────────────

    public function test_home_renders_in_arabic(): void
    {
        $this->actingAs($this->owner());
        $this->project('Arabic Site');

        app()->setLocale('ar');
        $html = $this->get('/admin')->getContent();

        $this->assertStringContainsString(__('home.state_all_clear'), $html, 'The state sentence is translated');
        $this->assertStringContainsString(__('home.projects_title'), $html);
        app()->setLocale('en');
    }

    public function test_home_never_renders_raw_health_enums(): void
    {
        $this->actingAs($this->owner());
        $this->project('Raw Enum Site', extra: ['health_status' => 'unknown', 'deploy_status' => 'not_deployed']);

        $html = $this->get('/admin')->getContent();

        $this->assertStringNotContainsString('not_deployed', $html);
        $this->assertStringNotContainsString('>unknown<', $html, 'Raw health enums must not render as labels');
    }

    // ── §ERRORS: graceful degradation ───────────────────────────────────

    public function test_a_failing_secondary_source_does_not_break_home(): void
    {
        $dashboard = new Dashboard;

        $result = $dashboard->safeSection(fn () => throw new \RuntimeException('activity source down'));

        $this->assertNull($result, 'A failing section source degrades to empty, not a 500');
    }

    // ── §PERFORMANCE: query sanity ──────────────────────────────────────

    public function test_home_query_count_stays_bounded_with_many_projects(): void
    {
        $this->actingAs($this->owner());

        for ($i = 1; $i <= 25; $i++) {
            $this->project("Perf Site {$i}");
        }

        DB::enableQueryLog();
        $this->get('/admin')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The per-project pulse reads stored state (no live health probes);
        // the bound catches N+1 accidents while allowing the linear point
        // reads the domain services already perform. Filament's own shell
        // costs a baseline too — the ceiling is generous, the trend matters.
        $this->assertLessThan(
            400,
            $queries,
            "Home issued {$queries} queries for 25 projects — a runaway N+1",
        );
    }
}
