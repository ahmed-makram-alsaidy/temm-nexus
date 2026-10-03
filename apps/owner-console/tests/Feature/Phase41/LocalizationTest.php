<?php

namespace Tests\Feature\Phase41;

use App\Models\User;
use App\Services\Access\Roles;
use App\Services\Localization\LocaleManager;
use App\Services\Platform\SetupState;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 0.4.0-rc.5 (Phase 41) — localization closure.
 *
 * Covers: locale persistence (Part E), Arabic/English resolution order
 * (C.2), RTL/LTR direction (C.6), the language switcher (C.3),
 * unauthenticated language (C.4), and the platform default locale (C.5).
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('platform.initialized');
    }

    protected function platformOwner(?string $locale = null): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => uniqid('owner').'@test.local',
            'password' => 'password-password-123',
            'platform_role' => Roles::PLATFORM_OWNER,
            'locale' => $locale,
        ]);
    }

    public function test_available_locales_are_english_and_arabic(): void
    {
        $this->assertSame(['en', 'ar'], LocaleManager::AVAILABLE);
        $this->assertSame(['en', 'ar'], array_keys(LocaleManager::available()));
    }

    public function test_direction_is_ltr_for_english_and_rtl_for_arabic(): void
    {
        $this->assertSame('ltr', LocaleManager::direction('en'));
        $this->assertSame('rtl', LocaleManager::direction('ar'));
    }

    public function test_authenticated_user_locale_wins(): void
    {
        $owner = $this->platformOwner('ar');
        $this->actingAs($owner);

        $response = $this->get('/admin');
        $response->assertOk();

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_user_locale_overrides_platform_default(): void
    {
        SetupState::set(LocaleManager::PLATFORM_DEFAULT_KEY, 'en');

        $owner = $this->platformOwner('ar');
        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_platform_default_applies_to_users_without_preference(): void
    {
        SetupState::set(LocaleManager::PLATFORM_DEFAULT_KEY, 'ar');

        $owner = $this->platformOwner(null);
        $this->actingAs($owner)->get('/admin')->assertOk();

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_admin_panel_renders_rtl_direction_in_arabic(): void
    {
        $owner = $this->platformOwner('ar');
        $this->actingAs($owner);

        $response = $this->get('/admin');
        $response->assertOk();

        // Filament derives `dir` from the filament-panels::layout.direction
        // translation, which the vendor Arabic pack sets to rtl.
        $this->assertStringContainsString('dir="rtl"', (string) $response->getContent());
    }

    public function test_admin_panel_renders_ltr_direction_in_english(): void
    {
        $owner = $this->platformOwner('en');
        $this->actingAs($owner);

        $response = $this->get('/admin');
        $response->assertOk();

        $this->assertStringContainsString('dir="ltr"', (string) $response->getContent());
    }

    public function test_guest_language_switch_persists_in_session(): void
    {
        // No user exists: the setup/login language is session-carried (C.4).
        $this->post('/locale', ['locale' => 'ar'])->assertRedirect();

        // While the platform is uninitialized, /admin redirects to /setup —
        // request ANY guest surface and the session choice must be honored.
        $this->get('/admin/login');
        $this->assertSame('ar', session(LocaleManager::SESSION_KEY));
    }

    public function test_language_switch_rejects_unknown_locales(): void
    {
        // The semantic guarantee: an unsupported locale is never applied.
        $this->post('/locale', ['locale' => 'fr']);
        $this->assertSame('en', app()->getLocale());
        $this->assertNull(session(LocaleManager::SESSION_KEY));
    }

    public function test_language_switch_persists_on_the_user_when_authenticated(): void
    {
        $owner = $this->platformOwner(null);
        $this->actingAs($owner);

        $this->post('/locale', ['locale' => 'ar'])->assertRedirect();

        $this->assertSame('ar', $owner->fresh()->locale);
        $this->assertSame('ar', app()->getLocale());
    }

    public function test_invalid_user_locale_falls_back_gracefully(): void
    {
        SetupState::set(LocaleManager::PLATFORM_DEFAULT_KEY, 'en');

        // A legacy/garbage value must never take the page down (C.2).
        $owner = $this->platformOwner('xx');
        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_setup_wizard_persists_platform_default_language(): void
    {
        // Simulate the setup step-2 submission path (C.4/C.5).
        SetupState::set(LocaleManager::PLATFORM_DEFAULT_KEY, 'ar');

        $this->assertSame('ar', LocaleManager::platformDefault());
    }

    public function test_platform_default_falls_back_to_english_when_unset(): void
    {
        $this->assertSame('en', LocaleManager::platformDefault());
    }
}
