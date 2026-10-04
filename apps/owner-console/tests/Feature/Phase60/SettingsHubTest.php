<?php

namespace Tests\Feature\Phase60;

use App\Filament\Pages\SettingsHub;
use App\Models\User;
use App\Services\Access\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.6.0 Phase B (§B2/§B13) — the Settings destination and its hierarchy.
 *
 * Settings is one map: Members · AI (Assistant · Developer Agents) ·
 * Connectors · System (Infrastructure · Services · Health · Topology).
 * Sub-pages keep their routes and capability checks; the hub only decides
 * what is worth showing (permission-aware density).
 */
class SettingsHubTest extends TestCase
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

    public function test_owner_sees_the_full_settings_hierarchy(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin/settings')->assertOk()->getContent();

        foreach ([
            __('settings.members_title'),
            __('settings.ai_title'),
            __('settings.ai_assistant'),
            __('settings.ai_agents'),
            __('settings.connectors_title'),
            __('settings.system_title'),
            __('settings.system_nodes'),
            __('settings.system_health'),
            __('settings.system_topology'),
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_hub_links_point_at_the_real_destinations(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/admin/settings')->getContent();

        $this->assertStringContainsString(\App\Filament\Pages\TeamManagement::getUrl(), $html);
        $this->assertStringContainsString(\App\Filament\Pages\NexusAiSettings::getUrl(), $html);
        $this->assertStringContainsString(\App\Filament\Pages\DeveloperAgentSettings::getUrl(), $html);
        $this->assertStringContainsString(\App\Filament\Pages\ConnectorCatalog::getUrl(), $html);
        $this->assertStringContainsString(\App\Filament\Pages\InfraNodes::getUrl(), $html);
    }

    public function test_moved_pages_keep_their_routes(): void
    {
        $this->actingAs($this->owner());

        // B8–B11: moving a concept never breaks a deep link.
        $this->get('/admin/team')->assertOk();
        $this->get('/admin/connectors')->assertOk();
        $this->get('/admin/infra-nodes')->assertOk();
        $this->get('/admin/infra-health')->assertOk();
        $this->get('/admin/onboarding')->assertOk();
    }

    public function test_user_without_any_settings_capability_cannot_open_the_hub(): void
    {
        $viewer = User::create([
            'name' => 'Bare User',
            'email' => uniqid('bare').'@test.local',
            'password' => 'password-password-123',
        ]);

        $this->actingAs($viewer);

        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_hub_sections_are_capability_filtered(): void
    {
        // A user with no capabilities would be denied at the door (see the
        // forbidden test); asserting the section builder itself keeps the
        // density guarantee honest — no capabilities, no sections.
        $bare = User::create([
            'name' => 'Bare User',
            'email' => uniqid('bare').'@test.local',
            'password' => 'password-password-123',
        ]);

        $access = \App\Filament\Support\PlatformAccess::current();
        if ($access->allowsPlatform(\App\Services\Access\Capability::TEAM_VIEW)
            || $access->allowsPlatform(\App\Services\Access\Capability::AI_CONFIGURE)
            || $access->allowsPlatform(\App\Services\Access\Capability::AGENTS_CONFIGURE)
            || $access->allowsPlatform(\App\Services\Access\Capability::CONNECTORS_VIEW)
            || $access->allowsPlatform(\App\Services\Access\Capability::INFRASTRUCTURE_VIEW)
        ) {
            $this->markTestSkipped('Fixture user unexpectedly holds platform capabilities.');
        }

        $this->actingAs($bare);

        $this->assertSame([], SettingsHub::hubSectionsForTesting());
    }
}
