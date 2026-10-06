<?php

namespace Tests\Feature\PhaseH;

use App\Filament\Pages\DeveloperAgentSettings;
use App\Filament\Pages\InfraHealth;
use App\Filament\Pages\InfraNodes;
use App\Filament\Pages\InfraServices;
use App\Filament\Pages\InfraTopology;
use App\Filament\Pages\NexusAiSettings;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\Pages\AuditLogList;
use App\Models\AgentRuntime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase H polish contracts — the presentation invariants shipped in Phase H
 * commits D–H: AI/Agent settings grouping with safe error vocabulary, the
 * Activity destination naming, localized infrastructure navigation, and the
 * light-first setup/landing chrome.
 *
 * Structure contracts only — no pixel or arbitrary CSS-string assertions
 * (the single color-scheme token below is the light-first theme contract
 * itself, not decoration matching).
 */
class PhaseHPolishContractsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    // ── H6 — AI Assistant settings grouping + vocabulary ────────────────

    public function test_ai_assistant_settings_group_transport_fields_behind_advanced(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(NexusAiSettings::getUrl())->assertOk()->getContent();

        // Advanced is a collapsed disclosure carrying the transport fields.
        $this->assertStringContainsString('<details class="nx-advanced"', $html);
        $this->assertStringContainsString(__('ai.advanced_section'), $html);
        // H7 vocabulary fix: the internal "Last safe error" wording is gone.
        $this->assertStringContainsString(__('ai.status_last_error'), $html);
        $this->assertStringNotContainsString('Last safe error', $html);
    }

    // ── H7 — Developer Agent settings grouping + error presentation ─────

    public function test_agent_settings_group_runtime_plumbing_behind_advanced(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(DeveloperAgentSettings::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('<details class="nx-advanced"', $html);
        $this->assertStringContainsString(__('agents.advanced_section'), $html);
    }

    public function test_agent_test_connection_reports_localized_category_not_raw_text(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // A managed runtime whose endpoint (http://opencode:4096) does not
        // exist in the test environment — a real AgentRuntimeException with
        // the RUNTIME_UNAVAILABLE category, never a raw exception string.
        $runtime = AgentRuntime::create([
            'driver' => 'opencode',
            'display_name' => 'Unreachable managed',
            'mode' => 'managed',
            'enabled' => true,
        ]);

        Livewire::actingAs($admin)->test(DeveloperAgentSettings::class)
            ->call('testRuntime', $runtime->id);

        $runtime->refresh();
        $this->assertSame('error', $runtime->status);
        $this->assertSame('failed', $runtime->last_test_status);
        // The persisted/presented message is the localized product line for
        // the category — commit 93681f4 removed the raw driver text.
        $this->assertSame(__('agents.error_runtime_unavailable'), $runtime->last_test_message);
    }

    // ── H10 — the Activity destination naming ────────────────────────────

    public function test_activity_destination_name_is_consistent_across_nav_title_and_breadcrumbs(): void
    {
        $this->assertSame(__('nav.activity'), AuditLogResource::getNavigationLabel());
        $this->assertSame(__('nav.activity'), (new AuditLogList)->getTitle());
        $this->assertStringContainsString(__('nav.activity'), implode(' · ', (new AuditLogList)->getBreadcrumbs()));
    }

    // ── R7 — localized infrastructure navigation ────────────────────────

    public function test_infrastructure_navigation_labels_use_the_nav_dictionary(): void
    {
        $this->assertSame(__('nav.nodes'), InfraNodes::getNavigationLabel());
        $this->assertSame(__('nav.services'), InfraServices::getNavigationLabel());
        $this->assertSame(__('nav.health'), InfraHealth::getNavigationLabel());
        $this->assertSame(__('nav.topology'), InfraTopology::getNavigationLabel());

        // Representative AR rendering: the same destinations translate.
        app()->setLocale('ar');
        $this->assertSame(__('nav.nodes'), InfraNodes::getNavigationLabel());
        $this->assertSame(__('nav.activity'), AuditLogResource::getNavigationLabel());
    }

    // ── H8 — setup chrome is light-first with labeled progress ──────────

    public function test_setup_chrome_is_light_first_with_labeled_progress(): void
    {
        // Rendering step 1 runs the system check, which probes Redis through
        // the phpredis extension — same skip contract as SetupWizardTest
        // (CI/the test container provides both; see TEST_CLASSIFICATION C.1).
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Setup chrome contract needs the phpredis-backed system check (CI provides it).');
        }

        User::query()->delete();
        \App\Models\PlatformSetting::query()->delete();
        Cache::forget('platform.initialized');

        try {
            $html = $this->get('/setup/step/1')->assertOk()->getContent();

            // Progress is stated in words, not bare numbers.
            $this->assertStringContainsString(__('setup.step_progress', ['step' => 1, 'total' => 12]), $html);
            // The step chips carry their labels for non-visual contexts.
            $this->assertStringContainsString('data-label=', $html);
            // Light-first theme contract; the legacy dark canvas is gone.
            $this->assertStringContainsString('color-scheme: light', $html);
            $this->assertStringNotContainsString('#0f172a', $html);
            // The wizard itself still gates on the system check.
            $this->assertStringContainsString('System Check', $html);
        } finally {
            Cache::forget('platform.initialized');
        }
    }

    // ── H9 — public landing shares the same light canvas ────────────────

    public function test_public_landing_is_light_first_and_aligned(): void
    {
        // RefreshDatabase seeded state has users, so the platform is
        // initialized and / renders the landing instead of redirecting.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(config('platform.brand'), $html);
        $this->assertStringContainsString(__('welcome.open_console'), $html);
        $this->assertStringContainsString('color-scheme: light', $html);
        $this->assertStringNotContainsString('#0f172a', $html);
    }
}
