<?php

// ── Nexus AI product configuration (0.4.0-rc.5, Phase 41) ────────────
// Product-facing AI settings live in the database (ai_provider_configs,
// platform_settings). This file only holds environment-level policy that
// must be knowable before any DB access.

return [

    /*
    |--------------------------------------------------------------------------
    | Fake / test provider visibility
    |--------------------------------------------------------------------------
    |
    | The `fake` provider (FakeAiDriver) exists for automated tests and local
    | development only. It must NEVER surface in a production UI: not in the
    | provider picker on Nexus AI Settings, not in the project Copilot form,
    | and not as a routable provider in ModelRouter.
    |
    | Default: allowed everywhere EXCEPT production. Operators may force it
    | off (or on) explicitly with AI_ALLOW_FAKE_PROVIDERS=true|false.
    |
    | NOTE: config files load very early — read APP_ENV via env(), never via
    | app()->isProduction(), which requires the container to be further along.
    |
    | Regression coverage: tests/Feature/Phase41/FakeProviderVisibilityTest.php
    */

    'allow_fake_providers' => env('AI_ALLOW_FAKE_PROVIDERS', ! in_array(env('APP_ENV'), ['production', 'prod'], true)),

    /*
    |--------------------------------------------------------------------------
    | Provider catalogue shown in Settings → Nexus AI
    |--------------------------------------------------------------------------
    |
    | Identifiers mirror AiProviderConfig::PROVIDERS / AiGateway drivers.
    | `fake` is appended dynamically when allow_fake_providers is true.
    */

    'providers' => [
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'gemini' => 'Gemini',
        'openrouter' => 'OpenRouter',
        'openai_compatible' => 'OpenAI-compatible',
    ],

    // Providers whose custom base URL is expected to change (already covered
    // by AiNetworkGuard); listed so the settings UI can mark the field
    // as required for them.
    'requires_base_url' => ['openai_compatible'],
];
