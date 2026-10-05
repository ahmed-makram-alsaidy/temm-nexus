<?php

namespace Tests\Feature\Phase60;

use App\Filament\Pages\NewProjectWizard;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Access\Roles;
use App\Services\ControlPlane\AdminAudit;
use App\Services\Product\JourneyState;
use App\Services\Product\PlatformPulse;
use App\Services\Product\ProjectPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 0.6.0 Phase D — the PROJECTS EXPERIENCE.
 *
 * The index answers "which project needs me / what stage is it in / how do I
 * open one" without ever becoming a database table again; the overview is the
 * canonical project command center. The acceptance criteria, in the mission's
 * own terms: attention-first deterministic ordering, decision cards with no
 * internal fields, no raw enums, translated surfaces (EN + AR), one primary
 * action on the overview, permission-aware CTAs, humanized activity,
 * technical identifiers only behind the disclosure, batched data access with
 * a real query bound, and Home/Overview never disagreeing about attention.
 */
class ProjectsExperienceTest extends TestCase
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

    protected function workspace(string $name): Workspace
    {
        return Workspace::create([
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)).'-'.uniqid(),
            'kind' => 'client',
            'status' => 'active',
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

    /** Seed a full FK chain so the project's migrate stage is genuinely running. */
    protected function seedRunningMigration(Project $project, string $status = 'running'): void
    {
        $sourceId = DB::table('migration_sources')->insertGetId([
            'project_id' => $project->id,
            'type' => 'postgres',
            'display_name' => $project->name.' source',
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $analysisId = DB::table('migration_analyses')->insertGetId([
            'project_id' => $project->id,
            'migration_source_id' => $sourceId,
            'run_id' => 'an-'.uniqid(),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $planId = DB::table('migration_plans')->insertGetId([
            'project_id' => $project->id,
            'migration_analysis_id' => $analysisId,
            'name' => $project->name.' plan',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('migration_runs')->insert([
            'project_id' => $project->id,
            'migration_plan_id' => $planId,
            'run_id' => 'run-'.uniqid(),
            'mode' => 'apply',
            'status' => $status,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── §D1: empty states ────────────────────────────────────────────────

    public function test_zero_projects_show_a_teaching_empty_state_not_a_table(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin/projects')->assertOk()->getContent();
        $wizard = NewProjectWizard::getUrl();

        $this->assertStringContainsString(__('projects.empty_title'), $html);
        $this->assertStringContainsString(__('projects.empty_body'), $html);
        $this->assertStringContainsString('href="'.$wizard.'"', $html, 'The empty state CTA must lead to the guided wizard');
        $this->assertStringContainsString(__('projects.empty_cta'), $html);
        $this->assertStringNotContainsString('No records', $html, 'The generic Filament empty table must be gone');
    }

    public function test_the_panel_denies_users_who_can_reach_nothing(): void
    {
        // §D12: server-side permissions are the source of truth. A user who
        // can reach no project and holds no role cannot enter the projects
        // surface at all — the panel middleware answers before any UI is
        // rendered (User::canAccessPanel).
        $this->actingAs($this->viewer());

        $this->get('/admin/projects')->assertForbidden();
    }

    // ── §D1: the product card ────────────────────────────────────────────

    public function test_one_project_renders_a_decision_card(): void
    {
        $this->actingAs($this->owner());
        $this->project('Solo Website');

        $html = $this->get('/admin/projects')->assertOk()->getContent();

        $this->assertStringContainsString('Solo Website', $html);
        $this->assertStringContainsString(__('projects.card_open'), $html);
        $this->assertStringContainsString(__('journey.stage_connect'), $html, 'The card shows the journey stage');
        $this->assertStringContainsString(__('journey.state_not_started'), $html, 'The card shows the journey state');
    }

    public function test_cards_never_expose_internal_fields_or_raw_enums(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Exposed Site', extra: [
            'db_name' => 'exposed_site_db',
            'api_domain' => 'api.exposed.example.com',
            'redis_prefix' => 'exposed_site',
            'deploy_status' => 'not_deployed',
        ]);

        $html = $this->get('/admin/projects')->getContent();

        $this->assertStringNotContainsString('exposed_site_db', $html, 'DB names never render on the index');
        $this->assertStringNotContainsString('api.exposed.example.com', $html, 'API domains never render on the index');
        $this->assertStringNotContainsString($project->slug, $html, 'Slugs never render on the index');
        $this->assertStringNotContainsString('exposed_site:', $html, 'Redis prefixes never render on the index');
        $this->assertStringNotContainsString('not_deployed', $html, 'Raw deployment enums never render');
        $this->assertStringNotContainsString('>unknown<', $html, 'Raw health enums never render as labels');
    }

    // ── §D1: attention-first deterministic ordering ──────────────────────

    public function test_index_orders_attention_first_then_active_then_recent_then_rest(): void
    {
        $this->actingAs($this->owner());

        $other = $this->project('Zed Quiet Site');
        $recent = $this->project('Recent Site');
        AdminAudit::record('PROJECT_SETTINGS_UPDATED', $recent, 'project', $recent->id);
        $active = $this->project('Active Site');
        $this->seedRunningMigration($active);
        $blocked = $this->project('Blocked Site', extra: ['health_status' => 'unhealthy']);

        $rows = PlatformPulse::for(PlatformAccess::current()->access())->indexRows();

        $this->assertSame(
            ['Blocked Site', 'Active Site', 'Recent Site', 'Zed Quiet Site'],
            $rows->pluck('project.name')->all(),
            'Ordering must be attention → active → recently meaningful → others',
        );

        $html = $this->get('/admin/projects')->getContent();
        $this->assertLessThan(
            strpos($html, 'Active Site'),
            strpos($html, 'Blocked Site'),
            'The blocked project renders ahead of the active one',
        );
        $this->assertLessThan(
            strpos($html, 'Zed Quiet Site'),
            strpos($html, 'Recent Site'),
            'A project with real activity renders ahead of a silent one',
        );
    }

    public function test_blocked_sorts_ahead_of_attention_within_the_attention_tier(): void
    {
        $this->actingAs($this->owner());

        // A non-blocking warning check degrades to NEEDS_ATTENTION.
        $warned = $this->project('Warned Site');
        DB::table('readiness_checks')->insert([
            'project_id' => $warned->id,
            'category' => 'Database',
            'check_key' => 'db.maintenance_window',
            'title' => 'Maintenance window not scheduled',
            'status' => 'warning',
            'blocks_production' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->project('Blocked Site', extra: ['health_status' => 'unhealthy']); // BLOCKED

        $rows = PlatformPulse::for(PlatformAccess::current()->access())->indexRows();

        $this->assertSame('Blocked Site', $rows->first()['project']->name, 'BLOCKED outranks NEEDS_ATTENTION');
    }

    // ── §D1: filters, search, view modes ─────────────────────────────────

    public function test_attention_filter_shows_only_projects_that_need_attention(): void
    {
        $this->actingAs($this->owner());
        $this->project('Calm Site');
        $this->project('Broken Site', extra: ['health_status' => 'unhealthy']);

        $html = $this->get('/admin/projects?filter=attention')->assertOk()->getContent();

        $this->assertStringContainsString('Broken Site', $html);
        $this->assertStringNotContainsString('Calm Site', $html);
    }

    public function test_environment_filter_narrows_to_one_environment(): void
    {
        $this->actingAs($this->owner());
        $this->project('Dev Site');
        $this->project('Prod Site', extra: ['environment' => 'production']);

        $html = $this->get('/admin/projects?env=production')->assertOk()->getContent();

        $this->assertStringContainsString('Prod Site', $html);
        $this->assertStringNotContainsString('Dev Site', $html);
    }

    public function test_workspace_filter_narrows_to_one_client(): void
    {
        $this->actingAs($this->owner());
        $acme = $this->workspace('Acme');
        $this->project('Acme Site', $acme);
        $this->project('Orphan Site');

        $html = $this->get('/admin/projects?ws='.$acme->id)->assertOk()->getContent();

        $this->assertStringContainsString('Acme Site', $html);
        $this->assertStringNotContainsString('Orphan Site', $html);
    }

    public function test_search_matches_name(): void
    {
        $this->actingAs($this->owner());
        $this->project('Alpha Website');
        $this->project('Beta CRM');

        $html = $this->get('/admin/projects?q=alpha')->assertOk()->getContent();

        $this->assertStringContainsString('Alpha Website', $html);
        $this->assertStringNotContainsString('Beta CRM', $html);
    }

    public function test_no_matches_state_offers_the_way_out(): void
    {
        $this->actingAs($this->owner());
        $this->project('Alpha Website');

        $html = $this->get('/admin/projects?q=nothing-matches-this')->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.empty_no_matches_title'), $html);
        $this->assertStringContainsString(__('projects.clear_filters'), $html);
    }

    public function test_compact_view_is_a_calm_table_without_technical_columns(): void
    {
        $this->actingAs($this->owner());
        $this->project('Compact Site', extra: ['db_name' => 'compact_site_db']);

        $html = $this->get('/admin/projects?view=compact')->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.col_project'), $html);
        $this->assertStringContainsString(__('projects.col_stage'), $html);
        $this->assertStringNotContainsString('compact_site_db', $html, 'The compact view is not the technical table');
        $this->assertStringNotContainsString('api_domain', $html);
    }

    // ── §D1/§D12: creation action + permissions ──────────────────────────

    public function test_new_project_action_routes_to_the_wizard(): void
    {
        $this->actingAs($this->owner());
        $this->project('Routed Site');

        $html = $this->get('/admin/projects')->getContent();

        $this->assertStringContainsString(
            'href="'.NewProjectWizard::getUrl().'"',
            $html,
            'The one creation action must be the guided wizard',
        );
    }

    public function test_viewer_sees_only_accessible_projects_and_no_create_cta(): void
    {
        $viewer = User::create([
            'name' => 'Workspace Viewer',
            'email' => uniqid('wsviewer').'@test.local',
            'password' => 'password-password-123',
        ]);
        $workspace = $this->workspace('Viewer Client');
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $viewer->id,
            'role' => Roles::VIEWER,
            'status' => 'active',
        ]);
        $this->project('Viewer Site', $workspace);
        $this->project('Hidden Site');

        $this->actingAs($viewer);

        $html = $this->get('/admin/projects')->assertOk()->getContent();

        $this->assertStringContainsString('Viewer Site', $html, 'A viewer sees their own project');
        $this->assertStringNotContainsString('Hidden Site', $html, 'A viewer never sees unreachable projects');
        $this->assertStringNotContainsString(
            'href="'.NewProjectWizard::getUrl().'"',
            $html,
            'A viewer without create capability never sees the create CTA',
        );
    }

    // ── §D10: Arabic ─────────────────────────────────────────────────────

    public function test_index_renders_in_arabic(): void
    {
        $this->actingAs($this->owner());
        $this->project('Arabic Site');

        app()->setLocale('ar');
        $html = $this->get('/admin/projects')->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.filter_all'), $html, 'Filter chips are translated');
        $this->assertStringContainsString(__('projects.view_cards'), $html, 'The view toggle is translated');
        $this->assertStringContainsString(__('projects.card_open'), $html, 'The open action is translated');
        $this->assertStringContainsString(__('journey.stage_connect'), $html, 'Journey stage labels stay translated');
        app()->setLocale('en');
    }

    public function test_problem_strings_render_translated_in_arabic(): void
    {
        $this->actingAs($this->owner());
        $this->project('Arabic Attention Site', extra: ['health_status' => 'unhealthy']);

        app()->setLocale('ar');

        // The pulse itself now speaks the user's language — the attention
        // item a Home and the index share comes out in Arabic.
        $pulse = ProjectPulse::for(Project::where('name', 'Arabic Attention Site')->first());
        $this->assertSame('لا نسخة احتياطية مسجلة', $pulse->warnings()[0]['title']);

        app()->setLocale('en');
    }

    // ── §D13: large counts, batched access ───────────────────────────────

    public function test_index_query_count_stays_bounded_with_many_projects(): void
    {
        $this->actingAs($this->owner());

        for ($i = 1; $i <= 30; $i++) {
            $this->project("Perf Site {$i}");
        }

        DB::enableQueryLog();
        $this->get('/admin/projects')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Batched: ~12 pulse queries + workspaces + last activity, then the
        // Filament shell baseline. The bound catches per-project N+1 storms
        // (the Home class of regression) while leaving headroom for chrome.
        $this->assertLessThan(
            100,
            $queries,
            "Projects index issued {$queries} queries for 30 projects — the batch path regressed",
        );
    }

    public function test_index_paginates_large_project_counts(): void
    {
        $this->actingAs($this->owner());

        for ($i = 1; $i <= 30; $i++) {
            $this->project("Page Site {$i}");
        }

        $html = $this->get('/admin/projects')->assertOk()->getContent();

        $this->assertSame(
            24,
            substr_count($html, 'nx-card nx-card--link'),
            'The first page shows exactly the page size, not the whole table',
        );
        $this->assertStringContainsString(__('projects.pagination_page', ['current' => 1, 'last' => 2]), $html);
    }

    public function test_second_page_shows_the_remaining_projects(): void
    {
        $this->actingAs($this->owner());

        // Zero-padded names keep lexical order == numeric order, so page 2's
        // contents are predictable.
        for ($i = 1; $i <= 30; $i++) {
            $this->project(sprintf('Paged Site %02d', $i));
        }

        $html = $this->get('/admin/projects?paged=2')->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.pagination_page', ['current' => 2, 'last' => 2]), $html);
        $this->assertStringContainsString('Paged Site 30', $html);
        $this->assertStringNotContainsString('Paged Site 01', $html, 'Page 2 must not repeat page 1 rows');
    }

    // ── §D2/§D4/§D5: the Project Overview ────────────────────────────────

    public function test_overview_header_carries_one_primary_action(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Command Site');

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'nx-hero__actions'), 'Exactly one hero action block');
        $this->assertSame(1, substr_count($html, __('projects.cta_connect')), 'Exactly one primary CTA label');
    }

    public function test_overview_primary_action_reviews_attention_when_attention_exists(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Blocked Command Site');
        DB::table('readiness_checks')->insert([
            'project_id' => $project->id,
            'category' => 'Database',
            'check_key' => 'db.maintenance_window',
            'title' => 'Maintenance window not scheduled',
            'status' => 'failed',
            'blocks_production' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.cta_review_attention'), $html);
        $pulse = ProjectPulse::for($project->refresh());
        $this->assertStringContainsString(
            'href="'.$pulse->blockers()[0]['url'].'"',
            $html,
            'The CTA lands on the problem\'s canonical page',
        );
    }

    public function test_overview_primary_action_follows_the_journey_when_clear(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Fresh Command Site');

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.cta_connect'), $html);
    }

    public function test_overview_marks_the_current_journey_stage(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Journey Site');
        $this->seedRunningMigration($project);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('labels.you_are_here'), $html);
        $this->assertStringContainsString(__('journey.stage_migrate'), $html);
        $this->assertStringContainsString(__('projects.journey_title'), $html);
    }

    public function test_overview_labels_source_connection_as_its_own_concept(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Source Site');
        $this->seedRunningMigration($project);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.fact_source'), $html);
        $this->assertStringContainsString(__('projects.source_connected'), $html);
    }

    public function test_overview_readiness_fact_never_claims_a_fake_zero(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Unvalidated Site');

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.readiness_not_run'), $html);
    }

    public function test_overview_attention_items_carry_the_specific_problem(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Check Site');
        DB::table('readiness_checks')->insert([
            'project_id' => $project->id,
            'category' => 'Backups',
            'check_key' => 'backup.verified',
            'title' => 'No verified backup',
            'status' => 'failed',
            'blocks_production' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString('No verified backup', $html, 'The item shows the problem\'s own title');
        $this->assertStringContainsString(__('projects.attention_title'), $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('blocks_production', $html, 'Internal column names never render');
    }

    public function test_overview_activity_is_humanized_and_relative(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Activity Site');
        AdminAudit::record('WIZARD_SOURCE_CONNECTED', $project, 'migration_source', 1);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString('Connected a source', $html);
        $this->assertStringNotContainsString('WIZARD_SOURCE_CONNECTED', $html, 'Raw audit verbs never render (§D6)');
        $this->assertStringContainsString(__('projects.activity_view'), $html);
    }

    public function test_overview_keeps_technical_identifiers_behind_the_disclosure(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Disclosed Site', extra: [
            'db_name' => 'disclosed_site_db',
            'api_domain' => 'api.disclosed.example.com',
            'redis_prefix' => 'disclosed_site',
        ]);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.technical_title'), $html, 'The disclosure exists');
        $this->assertStringContainsString('disclosed_site_db', $html, 'The DB name is still reachable — inside the disclosure');
        $this->assertStringContainsString('api.disclosed.example.com', $html, 'The API domain is still reachable — inside the disclosure');
        $this->assertStringContainsString('disclosed_site', $html, 'The Redis prefix is still reachable — inside the disclosure');
        $this->assertStringContainsString(__('projects.tech_project_id'), $html);
        $this->assertStringContainsString(__('projects.tech_redis_prefix'), $html);
    }

    public function test_overview_subheading_stops_leaking_technical_identifiers(): void
    {
        $this->actingAs($this->owner());
        $workspace = $this->workspace('Disclosed Client');
        $project = $this->project('Identity Site', $workspace, ['api_domain' => 'api.identity.example.com']);

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString('Disclosed Client', $html);
        $this->assertStringContainsString(__('projects.env_development'), $html);
        $this->assertStringNotContainsString('api.identity.example.com</p>', $html);
    }

    public function test_overview_renders_in_arabic(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Arabic Overview');

        app()->setLocale('ar');
        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.journey_title'), $html);
        $this->assertStringContainsString(__('projects.fact_progress'), $html);
        $this->assertStringContainsString(__('projects.cta_connect'), $html);
        app()->setLocale('en');
    }

    // ── §D5/§D9: Home and Overview must agree ────────────────────────────

    public function test_home_and_overview_use_the_same_attention_language(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Consistent Site', extra: ['health_status' => 'unhealthy']);

        $home = $this->get('/admin')->getContent();
        $overview = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->getContent();

        $stateLabel = JourneyState::BLOCKED->label();
        $this->assertStringContainsString($stateLabel, $overview, 'Overview names the journey state');
        $this->assertStringContainsString(
            'No backup recorded',
            $home,
            'Home shows the pulse warning the overview shares',
        );
        $this->assertStringContainsString('No backup recorded', $overview, 'The same problem text appears on the overview');
    }

    // ── §D12: permission-aware overview ──────────────────────────────────

    public function test_overview_write_actions_are_hidden_from_viewers(): void
    {
        $viewer = User::create([
            'name' => 'Project Viewer',
            'email' => uniqid('pviewer').'@test.local',
            'password' => 'password-password-123',
        ]);
        $workspace = $this->workspace('Perms Client');
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $viewer->id,
            'role' => Roles::VIEWER,
            'status' => 'active',
        ]);
        $project = $this->project('Perms Site', $workspace);

        $this->actingAs($viewer);
        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringNotContainsString(__('labels.run_health_check'), $html, 'A viewer never sees the health-check write CTA');
        $this->assertStringNotContainsString(__('labels.enable_maintenance'), $html, 'A viewer never sees maintenance controls');
    }

    public function test_overview_write_actions_remain_for_managers(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Manager Site');

        $html = $this->get(ProjectResource::getUrl('overview', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('labels.run_health_check'), $html);
    }

    // ── §D8: the settings hub ────────────────────────────────────────────

    public function test_settings_hub_groups_entries_and_keeps_routes(): void
    {
        $owner = $this->owner();
        // Team pages (users/roles/permissions) keep their legacy is_admin
        // gate — the hub must mirror whatever the destinations allow.
        $owner->forceFill(['is_admin' => true])->save();
        $this->actingAs($owner);
        $project = $this->project('Hub Site');

        $html = $this->get(ProjectResource::getUrl('settings', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringContainsString(__('projects.hub_general'), $html);
        $this->assertStringContainsString(__('projects.hub_environments'), $html);
        $this->assertStringContainsString(__('projects.hub_connections'), $html);
        // '&'-containing labels are HTML-escaped in markup, so assert the
        // plain-text description instead.
        $this->assertStringContainsString(__('projects.hub_team_desc'), $html);
        $this->assertStringContainsString(__('projects.hub_advanced'), $html);
        $this->assertStringContainsString(
            ProjectResource::getUrl('environments', ['record' => $project]),
            $html,
            'Hub cards link to the preserved destination routes',
        );
        $this->assertStringContainsString(__('projects.secret_no_env'), $html, 'Secret states render as states, never values');
    }

    public function test_settings_hub_hides_secrets_without_capability(): void
    {
        $viewer = User::create([
            'name' => 'Hub Viewer',
            'email' => uniqid('hubviewer').'@test.local',
            'password' => 'password-password-123',
        ]);
        $workspace = $this->workspace('Hub Client');
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $viewer->id,
            'role' => Roles::VIEWER,
            'status' => 'active',
        ]);
        $project = $this->project('Hub Perms Site', $workspace);

        $this->actingAs($viewer);
        $html = $this->get(ProjectResource::getUrl('settings', ['record' => $project]))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            ProjectResource::getUrl('secrets', ['record' => $project]),
            $html,
            'A viewer without secrets.manage is never offered the secrets destination',
        );
    }

    // ── §D11: graceful degradation ───────────────────────────────────────

    public function test_the_index_survives_a_missing_secondary_subsystem(): void
    {
        $this->actingAs($this->owner());
        $project = $this->project('Degrading Site', extra: ['health_status' => 'unhealthy']);
        $this->seedRunningMigration($project);

        // §D13/§ERRORS: a secondary subsystem being absent degrades its own
        // figures — it must never 500 the index or hide the projects.
        Schema::drop('cdc_checkpoints');
        Schema::drop('readiness_checks');

        $html = $this->get('/admin/projects')->assertOk()->getContent();

        $this->assertStringContainsString('Degrading Site', $html, 'The project still renders');
        $this->assertStringContainsString(__('journey.state_in_progress'), $html, 'Journey state still derives');
    }
}
