<?php

namespace Tests\Feature\Phase41;

use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Ai\NexusAiConfig;
use App\Services\Ai\ModelRouter;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use App\Services\Platform\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0-rc.5 (Phase 41, Part A) — Settings → Nexus AI.
 *
 * Covers: authorization (A.8), provider configuration UX (A.2/A.3), API key
 * safety (A.4 — encrypted at rest, masked display, never re-displayed),
 * Test Connection (A.6), model routing UX (A.5), the master switch, and the
 * configuration-hierarchy display (A.8).
 */
class NexusAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function owner(): User
    {
        return User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
    }

    protected function plainUser(): User
    {
        return User::create([
            'name' => 'Nobody',
            'email' => uniqid('nobody').'@test.local',
            'password' => 'password-password-123',
        ]);
    }

    public function test_platform_owner_can_open_settings_page(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin/nexus-ai-settings')
            ->assertOk()
            ->assertSee(__('ai.settings_title'));
    }

    public function test_user_without_ai_configure_capability_is_refused(): void
    {
        $this->actingAs($this->plainUser())
            ->get('/admin/nexus-ai-settings')
            ->assertForbidden();
    }

    public function test_saving_provider_stores_key_encrypted_and_masks_it(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $response = \Livewire::withQueryParams([])
            ->test(\App\Filament\Pages\NexusAiSettings::class)
            ->set('providerForm.provider', 'openai')
            ->set('providerForm.display_name', 'OpenAI Production')
            ->set('providerForm.model', 'gpt-test')
            ->set('providerForm.api_key', 'sk-super-secret-value-1234567890')
            ->call('saveProvider');

        $response->assertHasNoErrors();

        $config = AiProviderConfig::query()->whereNull('project_id')->where('provider', 'openai')->first();
        $this->assertNotNull($config);
        $this->assertSame('gpt-test', $config->model);

        // A.4 — encrypted at rest (raw DB value is ciphertext, not the key).
        $raw = \Illuminate\Support\Facades\DB::table('ai_provider_configs')
            ->where('id', $config->id)->value('secret_encrypted');
        $this->assertStringNotContainsString('sk-super-secret-value', (string) $raw);

        // A.4 — the page never re-displays the key; only a masked hint.
        $html = $this->get('/admin/nexus-ai-settings')->getContent();
        $this->assertStringNotContainsString('sk-super-secret-value', $html);
        $this->assertStringContainsString('sk-s…7890', $html);
    }

    public function test_api_key_input_is_not_repopulated_after_reload(): void
    {
        $this->actingAs($this->owner());

        AiProviderConfig::create([
            'provider' => 'anthropic',
            'display_name' => 'Anthropic',
            'model' => 'claude-test',
            'secret_encrypted' => 'sk-ant-existing-key-9876543210',
            'project_id' => null,
            'enabled' => true,
        ]);

        $html = $this->get('/admin/nexus-ai-settings')->getContent();

        // The stored key never reaches the browser in ANY form.
        $this->assertStringNotContainsString('sk-ant-existing-key-9876543210', $html);
    }

    public function test_new_provider_without_key_is_rejected(): void
    {
        $this->actingAs($this->owner());

        \Livewire::test(\App\Filament\Pages\NexusAiSettings::class)
            ->set('providerForm.provider', 'gemini')
            ->set('providerForm.display_name', 'Gemini')
            ->set('providerForm.api_key', '')
            ->call('saveProvider')
            ->assertHasErrors(['providerForm.api_key']);

        $this->assertSame(0, AiProviderConfig::query()->where('provider', 'gemini')->count());
    }

    public function test_test_connection_reports_safe_result_and_persists_history(): void
    {
        $this->actingAs($this->owner());

        $config = AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake Test',
            'model' => 'fake-model',
            'secret_encrypted' => 'fake-key-123456',
            'project_id' => null,
            'enabled' => true,
        ]);

        FakeAiDriver::reset();

        \Livewire::test(\App\Filament\Pages\NexusAiSettings::class)
            ->set('providerForm.provider', 'fake')
            ->call('runTest')
            ->assertSet('testResult.ok', true);

        $config->refresh();
        $this->assertSame('connected', $config->status);
        $this->assertNotNull($config->last_tested_at);
        $this->assertNotNull($config->last_test_message);

        // Safe message: no exception text, no key material.
        $this->assertStringNotContainsString('fake-key', (string) $config->last_test_message);
    }

    public function test_role_overrides_route_models(): void
    {
        $this->actingAs($this->owner());

        $config = AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake',
            'model' => 'fake-default',
            'project_id' => null,
            'enabled' => true,
        ]);

        FakeAiDriver::reset();

        \Livewire::test(\App\Filament\Pages\NexusAiSettings::class)
            ->set('providerForm.provider', 'fake')
            ->set('roleModels.reasoning', 'fake-reasoning-model')
            ->call('saveRouting')
            ->assertHasNoErrors();

        $profile = AiModelProfile::query()
            ->where('ai_provider_config_id', $config->id)
            ->where('name', 'reasoning')->first();
        $this->assertNotNull($profile);
        $this->assertSame('fake-reasoning-model', $profile->model);

        // A.5 — one provider + one model still works for untouched roles.
        $route = (new ModelRouter)->resolve(ModelRouter::ROLE_DEFAULT);
        $this->assertSame('fake-default', $route['model']);
        $route = (new ModelRouter)->resolve(ModelRouter::ROLE_REASONING);
        $this->assertSame('fake-reasoning-model', $route['model']);
    }

    public function test_master_switch_disables_routing(): void
    {
        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        $this->assertTrue((new ModelRouter)->isConfigured());

        NexusAiConfig::setAiEnabled(false);
        $this->assertFalse(NexusAiConfig::aiEnabled());
        $this->assertFalse((new ModelRouter)->isConfigured());

        NexusAiConfig::setAiEnabled(true);
        $this->assertTrue((new ModelRouter)->isConfigured());
    }

    public function test_project_scoped_provider_is_reported_but_platform_default_is_shown(): void
    {
        $this->actingAs($this->owner());

        $project = \App\Models\Project::create([
            'name' => 'Scoped Project',
            'slug' => 'scoped-'.uniqid(),
            'status' => 'active',
        ]);

        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Project-scoped',
            'model' => 'fake-model',
            'project_id' => $project->id, // scoped, not platform
            'enabled' => true,
        ]);

        $component = \Livewire::test(\App\Filament\Pages\NexusAiSettings::class);
        $this->assertSame(1, $component->instance()->projectScopedProviderCount());
        $component->assertSee(__('ai.status_scope_platform'));
    }
}
