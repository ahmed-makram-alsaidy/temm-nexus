<?php

namespace App\Services\Ai;

use App\Models\AiProviderConfig;
use App\Services\Platform\SetupState;

/**
 * 0.4.0-rc.5 (Phase 41) — platform-level Nexus AI policy helpers.
 *
 * Two switches back the Settings → Nexus AI page:
 *
 *   - `ai.enabled` (platform_settings): the master on/off switch for the
 *     assistant. Off means ModelRouter resolves nothing and the UI shows
 *     the "AI disabled" empty state. It is NOT a security boundary — every
 *     consumer still enforces capabilities.
 *
 *   - fake-provider visibility (config `nexus-ai.allow_fake_providers`):
 *     the FakeAiDriver provider must never appear in a production UI. It is
 *     hidden from pickers, listings and routing unless explicitly allowed.
 */
final class NexusAiConfig
{
    public const AI_ENABLED_KEY = 'ai.enabled';

    public static function aiEnabled(): bool
    {
        try {
            $value = SetupState::get(self::AI_ENABLED_KEY);
        } catch (\Throwable) {
            return true;
        }

        // Unset = enabled by default (existing installations keep working).
        // Anything explicitly falsy ('0', false, 'false') disables.
        if ($value === null) {
            return true;
        }

        return ! in_array($value, [false, '0', 0, 'false'], true);
    }

    public static function setAiEnabled(bool $enabled): void
    {
        SetupState::set(self::AI_ENABLED_KEY, $enabled);
    }

    /** May the fake/test provider appear in UIs and routing right now? */
    public static function fakeProvidersAllowed(): bool
    {
        return (bool) config('nexus-ai.allow_fake_providers', ! app()->isProduction());
    }

    /** Provider identifiers safe to offer in a UI (fake hidden unless allowed). */
    public static function selectableProviders(): array
    {
        $providers = (array) config('nexus-ai.providers', [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Gemini',
            'openrouter' => 'OpenRouter',
            'openai_compatible' => 'OpenAI-compatible',
        ]);

        if (self::fakeProvidersAllowed()) {
            $providers['fake'] = 'Fake (testing)';
        }

        return $providers;
    }

    /** Query scope hiding fake provider rows when they must not be visible. */
    public static function scopeVisible(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        if (self::fakeProvidersAllowed()) {
            return $query;
        }

        return $query->where('provider', '!=', AiProviderConfig::PROVIDERS['fake'] ?? 'fake');
    }
}
