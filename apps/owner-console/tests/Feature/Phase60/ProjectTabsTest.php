<?php

namespace Tests\Feature\Phase60;

use App\Filament\Support\ProjectTabs;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.6.0 Phase B (§B4/§B5) — the project context tab model.
 *
 * A project is ONE product area with contextual tabs, not 35 sidebar links:
 * Overview · Migration · Data · Access · Build · Operate · Settings. Every
 * 0.5.0 destination stays reachable — re-homed, never deleted.
 */
class ProjectTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_tabs_are_exactly_the_seven_accepted_areas(): void
    {
        $this->assertSame(
            ['overview', 'migration', 'data', 'access', 'build', 'operate', 'settings'],
            ProjectTabs::primaryKeys(),
        );
    }

    public function test_every_project_page_belongs_to_exactly_one_tab(): void
    {
        $registered = \App\Filament\Resources\Projects\ProjectResource::getPages();

        // Resource CRUD lives OUTSIDE project context (the projects list,
        // the legacy create route and the edit form are not project-scoped
        // destinations). Secondary views of another page share its tab.
        $outsideProjectContext = ['index', 'create', 'edit'];
        $aliases = ['records', 'function-editor', 'function-tester'];

        foreach (array_keys($registered) as $slug) {
            if (in_array($slug, $outsideProjectContext, true)) {
                continue;
            }

            if (in_array($slug, $aliases, true)) {
                $this->assertNotNull(ProjectTabs::tabOf($slug), "Aliased page {$slug} must resolve through its parent");
                continue;
            }

            $this->assertNotNull(
                ProjectTabs::tabOf($slug),
                "Project page {$slug} is not reachable from any primary tab — it would be orphaned by the shell",
            );
        }
    }

    public function test_secondary_views_share_their_parent_tab(): void
    {
        $this->assertSame('data', ProjectTabs::tabOf('records'));
        $this->assertSame('build', ProjectTabs::tabOf('function-editor'));
        $this->assertSame('build', ProjectTabs::tabOf('function-tester'));
        $this->assertSame('migration', ProjectTabs::tabOf('cutover'));
        $this->assertSame('migration', ProjectTabs::tabOf('copilot'));
        $this->assertSame('operate', ProjectTabs::tabOf('readiness'));
    }

    public function test_tab_bar_renders_primary_tabs_and_active_secondary_items(): void
    {
        $owner = User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $project = Project::create([
            'name' => 'Tab Project',
            'slug' => 'tab-project-'.uniqid(),
            'status' => 'active',
            'environment' => 'development',
        ]);

        $this->actingAs($owner);

        $html = $this->get(\App\Filament\Resources\Projects\ProjectResource::getUrl('cutover', ['record' => $project]))
            ->assertOk()
            ->getContent();

        // Primary tabs present, in product order.
        $order = 0;
        foreach (['nav.overview' => 'Overview', 'nav.tab_migration' => 'Migration', 'nav.tab_data' => 'Data', 'nav.tab_access' => 'Access', 'nav.group_build' => 'Build', 'nav.group_operate' => 'Operate', 'nav.settings' => 'Settings'] as $key => $label) {
            $position = strpos($html, '>'.$label.'<');
            $this->assertNotFalse($position, "Primary tab {$label} missing from the project tab bar");
            $this->assertGreaterThan($order, $position, "Primary tab {$label} out of product order");
            $order = $position;
        }

        // The active tab's secondary row shows the Migration journey first,
        // then the operator tools. The absorbed pages (Migration Center,
        // Cutover, Copilot…) resolve TO the journey via deep-link aliases.
        $this->assertStringContainsString('>'.__('nav.migration').'<', $html);
        $this->assertStringContainsString('>'.__('nav.migrations').'<', $html);
        $this->assertStringContainsString('>'.__('nav.schema_diff').'<', $html);

        // Secondary destinations of OTHER tabs are not rendered here —
        // this is the tab model, not the 35-link sidebar dump.
        $this->assertStringNotContainsString('>'.__('nav.sql_editor').'<', $html);
        $this->assertStringNotContainsString('>'.__('nav.api_keys').'<', $html);
    }

    public function test_the_35_link_project_sidebar_no_longer_exists(): void
    {
        $owner = User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $project = Project::create([
            'name' => 'Sidebar Project',
            'slug' => 'sidebar-project-'.uniqid(),
            'status' => 'active',
            'environment' => 'development',
        ]);

        $this->actingAs($owner);

        $html = $this->get(\App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]))
            ->assertOk()
            ->getContent();

        // The old subnav surfaces are gone from project pages.
        $this->assertStringNotContainsString('cp-workspace', $html, 'The old project sidebar must not render');
        $this->assertStringNotContainsString('All Projects ←', $html);
        $this->assertStringNotContainsString('← All Projects', $html);
    }
}
