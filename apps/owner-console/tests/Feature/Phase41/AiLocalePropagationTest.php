<?php

namespace Tests\Feature\Phase41;

use App\Models\AiProviderConfig;
use App\Models\User;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use App\Services\Ai\ConversationEngine;
use App\Services\Ai\ModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.0-rc.5 (Phase 41, C.14/C.15) — Nexus AI follows the user's UI locale.
 *
 * The system prompt carries a LANGUAGE directive derived from app locale:
 * Arabic UI → Arabic answers by default. The user may still write in
 * another language. Tools keep returning canonical structured values
 * (translation is presentation-only) and no tenant/AI authorization
 * behavior changes with locale.
 */
class AiLocalePropagationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeAiDriver::reset();
    }

    protected function tearDown(): void
    {
        FakeAiDriver::reset();
        app()->setLocale('en');
        parent::tearDown();
    }

    protected function engine(): ConversationEngine
    {
        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        $user = User::create([
            'name' => 'Platform Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
        ]);
        $this->be($user);

        $access = \App\Filament\Support\PlatformAccess::current()->access();

        return new ConversationEngine(
            \App\Services\Ai\AiContext::platform($access, 'nexus-ai'),
            new ModelRouter,
        );
    }

    public function test_system_prompt_requests_arabic_when_ui_locale_is_arabic(): void
    {
        app()->setLocale('ar');
        $engine = $this->engine();

        $engine->turn('ما الذي يحتاج انتباهي؟', [], ModelRouter::ROLE_DEFAULT);

        $messages = FakeAiDriver::$lastComplete['messages'] ?? [];
        $system = (string) ($messages[0]['content'] ?? '');

        $this->assertStringContainsString('LANGUAGE', $system);
        $this->assertStringContainsString('Arabic', $system);
    }

    public function test_system_prompt_requests_english_when_ui_locale_is_english(): void
    {
        app()->setLocale('en');
        $engine = $this->engine();

        $engine->turn('What needs my attention?', [], ModelRouter::ROLE_DEFAULT);

        $messages = FakeAiDriver::$lastComplete['messages'] ?? [];
        $system = (string) ($messages[0]['content'] ?? '');

        $this->assertStringContainsString('English', $system);
        $this->assertStringNotContainsString('in Arabic', $system);
    }

    public function test_locale_does_not_change_ai_authorization(): void
    {
        // A user without ai.platform can never open the platform scope —
        // in EITHER language (C.14 must not weaken scope isolation).
        $user = User::create([
            'name' => 'Nobody',
            'email' => uniqid('nobody').'@test.local',
            'password' => 'password-password-123',
        ]);
        $this->be($user);

        AiProviderConfig::create([
            'provider' => 'fake',
            'display_name' => 'Fake',
            'model' => 'fake-model',
            'project_id' => null,
            'enabled' => true,
        ]);

        $access = \App\Filament\Support\PlatformAccess::current()->access();
        $engine = new ConversationEngine(
            \App\Services\Ai\AiContext::platform($access, 'nexus-ai'),
            new ModelRouter,
        );

        app()->setLocale('ar');
        $result = $engine->turn('مرحبا', [], ModelRouter::ROLE_DEFAULT);

        $this->assertFalse($result['ok']);
    }
}
