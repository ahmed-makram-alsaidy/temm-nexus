<?php

namespace Tests\Feature\Phase60;

use App\Filament\Support\ProductNavigation;
use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.6.0 Phase B (§B1/§B3/§B8/§B9/§B10/§B11) — the simplified platform shell.
 *
 * The sidebar is a map, not a database: five product destinations plus the
 * compact AI group. Everything the audit removed is asserted ABSENT:
 * the project-dependent Operations group, top-level Connectors, top-level
 * Infrastructure, Search-as-a-setting, and Get started.
 */
class ProductNavigationTest extends TestCase
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

    public function test_platform_entries_are_exactly_the_six_accepted_destinations(): void
    {
        $this->assertSame(
            ['Home', 'Projects', 'Clients', 'Activity', 'AI', 'Settings'],
            ProductNavigation::PLATFORM_ENTRIES,
        );

        $this->assertLessThanOrEqual(
            8,
            count(ProductNavigation::PLATFORM_ENTRIES),
            'The platform sidebar is a map: at most 8 primary destinations.'
        );
    }

    public function test_sidebar_renders_the_simplified_platform_navigation(): void
    {
        $this->actingAs($this->owner());

        $sidebar = $this->sidebarHtml($this->get('/admin')->assertOk()->getContent());

        foreach (['Home', 'Projects', 'Clients', 'Activity', 'Settings'] as $label) {
            $this->assertStringContainsString($label, $sidebar, "Missing platform nav item: {$label}");
        }
    }

    public function test_operations_group_is_gone_from_the_platform_sidebar(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin')->getContent();

        // B3: project-operational links must not appear in the platform
        // sidebar — they belong to the project tab bar (Operate).
        $this->assertStringNotContainsString('Operations', $html, 'The Operations group must not exist at platform level');

        // Not twice, not once: their product home is the project context.
        $sidebar = $this->sidebarHtml($html);
        $this->assertStringNotContainsString('>Queues<', $sidebar);
        $this->assertStringNotContainsString('>Webhooks<', $sidebar);
        $this->assertStringNotContainsString('>Realtime<', $sidebar);
    }

    public function test_infrastructure_and_connectors_and_onboarding_are_not_top_level(): void
    {
        $this->actingAs($this->owner());

        $sidebar = $this->sidebarHtml($this->get('/admin')->getContent());

        $this->assertStringNotContainsString('>Infrastructure<', $sidebar, 'Infrastructure moved to Settings ▸ System');
        $this->assertStringNotContainsString('>Connectors<', $sidebar, 'Connectors moved to Settings');
        $this->assertStringNotContainsString('>Get started<', $sidebar, 'Onboarding left the permanent sidebar');
        $this->assertStringNotContainsString('>Nodes<', $sidebar);
        $this->assertStringNotContainsString('>Topology<', $sidebar);
    }

    public function test_search_is_not_a_settings_destination(): void
    {
        $this->actingAs($this->owner());

        $sidebar = $this->sidebarHtml($this->get('/admin')->getContent());

        $this->assertStringNotContainsString('>Search<', $sidebar, 'B8: Search lives in the topbar + "/" shortcut, not the sidebar');

        // The route survives for compatibility.
        $this->get('/admin/search')->assertOk();
    }

    public function test_nexus_ai_and_developer_agent_share_one_compact_group(): void
    {
        $this->actingAs($this->owner());

        $sidebar = $this->sidebarHtml($this->get('/admin')->getContent());

        $this->assertStringContainsString('Nexus AI', $sidebar);
        $this->assertStringContainsString('Developer Agent', $sidebar);
    }

    /** Extract only the sidebar navigation region of a rendered page. */
    private function sidebarHtml(string $html): string
    {
        if (preg_match('/<nav[^>]*fi-sidebar[^>]*>(.*?)<\/nav>/s', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/<aside[^>]*>(.*?)<\/aside>/s', $html, $m)) {
            return $m[1];
        }

        return $html;
    }
}
