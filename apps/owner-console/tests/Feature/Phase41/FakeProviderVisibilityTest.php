<?php

namespace Tests\Feature\Phase41;

use App\Models\AiProviderConfig;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Ai\NexusAiConfig;
use App\Services\Ai\ModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0-rc.5 (Phase 41, A.7) — fake/test providers never surface in a
 * production UI.
 *
 * The regression: with `nexus-ai.allow_fake_providers` disabled, the fake
 * provider is invisible in Settings → Nexus AI, excluded from the provider
 * picker, and never routed by ModelRouter — while real providers keep
 * working. In local/testing it stays available.
 */
class FakeProviderVisibilityTest extends TestCase
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

    public function test_fake_provider_is_allowed_outside_production_by_default(): void
    {
        // APP_ENV=testing in the suite.
        $this->assertTrue(NexusAiConfig::fakeProvidersAllowed());
    }

    public function test_fake_provider_is_hidden_when_disabled(): void
    {
        config(['nexus-ai.allow_fake_providers' => false]);
        $this->assertFalse(NexusAiConfig::fakeProvidersAllowed());

        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake (testing)',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        // Selectable provider list excludes fake.
        $this->assertArrayNotHasKey('fake', NexusAiConfig::selectableProviders());

        // The settings page never lists it…
        $this->actingAs($this->owner());
        $html = $this->get('/admin/nexus-ai-settings')->getContent();
        $this->assertStringNotContainsString('Fake (testing)', $html);

        // …and ModelRouter does not route to it.
        $this->assertNull((new ModelRouter)->resolve());
        $this->assertFalse((new ModelRouter)->isConfigured());
    }

    public function test_real_provider_still_routes_when_fake_is_hidden(): void
    {
        config(['nexus-ai.allow_fake_providers' => false]);

        AiProviderConfig::create([
            'provider' => 'openai',
            'display_name' => 'OpenAI Production',
            'model' => 'gpt-real',
            'project_id' => null,
            'enabled' => true,
        ]);

        $route = (new ModelRouter)->resolve();
        $this->assertNotNull($route);
        $this->assertSame('gpt-real', $route['model']);
        $this->assertSame('openai', $route['provider']->provider);
    }

    public function test_fake_provider_is_visible_again_when_explicitly_allowed(): void
    {
        config(['nexus-ai.allow_fake_providers' => true]);

        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake (testing)',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        $this->assertArrayHasKey('fake', NexusAiConfig::selectableProviders());
        $this->assertNotNull((new ModelRouter)->resolve());
    }

    public function test_fake_provider_row_is_hidden_from_settings_listing(): void
    {
        config(['nexus-ai.allow_fake_providers' => false]);

        $fake = AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake (testing)',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        $listed = \App\Filament\Pages\NexusAiSettings::configuredGlobalProviders();
        $this->assertNotContains($fake->id, array_map(fn ($c) => $c->id, $listed));

        $this->assertNull(\App\Filament\Pages\NexusAiSettings::globalProvider('fake'));
    }
}
