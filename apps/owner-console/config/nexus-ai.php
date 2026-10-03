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

    /*
    |--------------------------------------------------------------------------
    | Transport telemetry (0.4.0-rc.7)
    |--------------------------------------------------------------------------
    |
    | Safe wire-level diagnostics for every AI provider call, written through
    | App\Services\ControlPlane\Ai\TransportTelemetry to the `nexus-ai` log
    | channel: final URL, method, status code, Content-Type, effective
    | User-Agent, request JSON FIELD NAMES and the `stream` value. Header
    | values are logged only for User-Agent / Content-Type / Accept;
    | Authorization and the API key are structurally excluded and the key is
    | redacted from any response body preview.
    */

    'telemetry' => [
        'enabled' => env('NEXUS_AI_TELEMETRY', true),
        'channel' => env('NEXUS_AI_TELEMETRY_CHANNEL', 'nexus-ai'),
        'body_log_bytes' => env('NEXUS_AI_TELEMETRY_BODY_BYTES', 300),
    ],

    // Local development ONLY: permits http://127.0.0.1 / localhost AI base
    // URLs so the acceptance flow can run against the shipped mock provider
    // (scripts/mock-agentrouter.php). Default false; never enable in prod.
    'allow_loopback_endpoints' => env('AI_ALLOW_LOOPBACK_ENDPOINTS', false),
];
