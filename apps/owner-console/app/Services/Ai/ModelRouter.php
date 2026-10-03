<?php

namespace App\Services\Ai;

use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;

/**
 * 0.4.0 §24 — configurable model ROUTING, not mandatory model count.
 *
 * The mission is explicit that an operator must not be required to configure
 * three models. So:
 *
 *   - every role resolves through an ordered fallback chain;
 *   - the last link in every chain is "whatever the enabled default provider
 *     offers", so a platform with ONE provider and ONE model works completely;
 *   - a role is a *hint*, never a requirement.
 *
 * Roles
 *   default   — everyday questions ("what needs attention?")
 *   reasoning — multi-step diagnosis ("why is this project stuck?")
 *   code      — anything that will produce or review a patch
 *
 * Resolution order for a role:
 *   1. an explicit `ai_model_profiles` row named for the role
 *   2. the enabled default provider's own model
 *   3. any enabled provider with a model set
 *
 * A returned route always names a real `AiProviderConfig`, so callers never
 * have to second-guess which vendor answered.
 */
final class ModelRouter
{
    public const ROLE_DEFAULT = 'default';

    public const ROLE_REASONING = 'reasoning';

    public const ROLE_CODE = 'code';

    /** Role → the model-profile names that satisfy it, in priority order. */
    public const ROLE_PROFILES = [
        self::ROLE_DEFAULT => ['default', 'fast', 'assistant', 'planner'],
        self::ROLE_REASONING => ['reasoning', 'diagnosis', 'deep', 'planner'],
        self::ROLE_CODE => ['code', 'builder', 'patch', 'planner'],
    ];

    public const ROLES = [self::ROLE_DEFAULT, self::ROLE_REASONING, self::ROLE_CODE];

    public function __construct(
        private readonly ?int $preferredProviderId = null,
    ) {}

    /**
     * Resolve a role to a concrete provider + model.
     *
     * @return array{provider: AiProviderConfig, model: ?string, role: string, source: string}|null
     *                                                                                              null when no provider is configured at all.
     */
    public function resolve(string $role = self::ROLE_DEFAULT): ?array
    {
        $role = in_array($role, self::ROLES, true) ? $role : self::ROLE_DEFAULT;

        // 0.4.0-rc.5: the platform master switch (Settings → Nexus AI) wins.
        // Off = nothing resolves, the UI shows the "AI disabled" state.
        if (! NexusAiConfig::aiEnabled()) {
            return null;
        }

        $provider = $this->preferredProvider();

        // 1 — an explicit profile for this role.
        if ($provider !== null) {
            foreach (self::ROLE_PROFILES[$role] as $profileName) {
                $profile = $this->profile($provider, $profileName);
                if ($profile !== null && $profile->model) {
                    return [
                        'provider' => $profile->provider ?? $provider,
                        'model' => $profile->model,
                        'role' => $role,
                        'source' => 'profile:'.$profileName,
                    ];
                }
            }
        }

        // 2 — the provider's own default model.
        if ($provider !== null && $provider->model) {
            return [
                'provider' => $provider,
                'model' => $provider->model,
                'role' => $role,
                'source' => 'provider_default',
            ];
        }

        // 3 — any enabled provider that has a model.
        $fallback = $this->anyEnabledWithModel();
        if ($fallback !== null) {
            return [
                'provider' => $fallback,
                'model' => $fallback->model,
                'role' => $role,
                'source' => 'fallback_provider',
            ];
        }

        return null;
    }

    /** Is the assistant usable at all? §32 empty state depends on this. */
    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * What an operator would see on a settings screen: which model answers each
     * role, and where that choice came from.
     *
     * @return list<array{role: string, label: string, provider: ?string, model: ?string, source: string}>
     */
    public function routingTable(): array
    {
        $out = [];

        foreach (self::ROLES as $role) {
            $route = $this->resolve($role);

            // Null route = nothing configured at all (rc.5: the settings
            // page renders this table BEFORE any provider exists).
            $out[] = [
                'role' => $role,
                'label' => self::label($role),
                'provider' => $route === null ? null : ($route['provider']->display_name ?? $route['provider']->provider ?? null),
                'model' => $route['model'] ?? null,
                'source' => $route['source'] ?? 'unconfigured',
            ];
        }

        return $out;
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::ROLE_DEFAULT => __('ai.role_default'),
            self::ROLE_REASONING => __('ai.role_reasoning'),
            self::ROLE_CODE => __('ai.role_code'),
            default => ucfirst($role),
        };
    }

    // ─────────────────────────────────────────────────────────────────

    private function preferredProvider(): ?AiProviderConfig
    {
        try {
            if ($this->preferredProviderId !== null) {
                $config = AiProviderConfig::query()
                    ->whereKey($this->preferredProviderId)
                    ->where('enabled', true)
                    ->first();
                if ($config) {
                    return $config;
                }
            }

            // Anthropic/Gemini/OpenAI/OpenRouter/openai-compatible are all just
            // rows here; nothing in this class names a vendor. The fake/test
            // provider never routes in production UIs (rc.5, A.7).
            return NexusAiConfig::scopeVisible(
                AiProviderConfig::query()->where('enabled', true)
            )
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function profile(AiProviderConfig $provider, string $name): ?AiModelProfile
    {
        try {
            return AiModelProfile::query()
                ->where('ai_provider_config_id', $provider->getKey())
                ->where('name', $name)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function anyEnabledWithModel(): ?AiProviderConfig
    {
        try {
            return NexusAiConfig::scopeVisible(
                AiProviderConfig::query()
                    ->where('enabled', true)
                    ->whereNotNull('model')
                    ->where('model', '!=', '')
            )
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
