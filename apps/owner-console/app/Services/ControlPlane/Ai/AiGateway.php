<?php

namespace App\Services\ControlPlane\Ai;

use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use App\Models\AiUsageRecord;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

/**
 * Phase 25F — provider-agnostic AI gateway.
 *
 * Resolves model profiles (planner/builder/validator), dispatches to the
 * right driver, records usage (tokens; cost ONLY when operator-configured
 * pricing exists), and guards custom base URLs against SSRF.
 */
class AiGateway
{
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    public function registerDefaults(): void
    {
        $this->drivers = [
            'openai' => new OpenAiCompatibleDriver,
            'openrouter' => new OpenAiCompatibleDriver,
            'openai_compatible' => new OpenAiCompatibleDriver,
            'anthropic' => new AnthropicDriver,
            'gemini' => new GeminiDriver,
            'fake' => new FakeAiDriver,
        ];
    }

    public function driver(AiProviderConfig $config): AiDriver
    {
        $driver = $this->drivers[$config->provider] ?? null;
        abort_if($driver === null, 422, "No AI driver for provider '{$config->provider}'");
        abort_unless($config->enabled, 422, 'AI provider is disabled.');

        return $driver;
    }

    /** Resolve a logical model profile to its provider config + model. */
    public function profile(Project $project, string $purpose): array
    {
        $profile = AiModelProfile::query()
            ->whereHas('provider', fn ($q) => $q->where('enabled', true)->where(function ($qq) use ($project) {
                $qq->whereNull('project_id')->orWhere('project_id', $project->id);
            }))
            ->where('name', $purpose)
            ->orderByDesc('id')
            ->first();

        if ($profile) {
            return ['config' => $profile->provider, 'model' => $profile->model ?? $profile->provider->model, 'profile' => $profile->name];
        }

        // Fallback: any enabled provider for this project (or global).
        $config = AiProviderConfig::where('enabled', true)
            ->where(function ($q) use ($project) {
                $q->whereNull('project_id')->orWhere('project_id', $project->id);
            })
            ->orderByDesc('project_id') // prefer project-scoped
            ->orderByDesc('id')
            ->first();
        abort_if($config === null, 422, 'No AI provider configured. Add one in the Copilot settings.');

        return ['config' => $config, 'model' => $config->model, 'profile' => $purpose];
    }

    /** Complete with usage recording. Messages must already be minimized/redacted. */
    public function complete(Project $project, array $resolved, array $messages, array $options = []): array
    {
        /** @var AiProviderConfig $config */
        $config = $resolved['config'];
        if ($config->provider !== 'fake') {
            // SSRF guard applies to real network drivers only; the built-in
            // FakeAiDriver never leaves the process (tests/local only).
            AiNetworkGuard::assertSafeBaseUrl($config);
        }

        $driver = $this->driver($config);
        $result = $driver->complete($config, $messages, $options);

        $usage = $result['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0];
        $pricing = $config->pricing;
        $cost = null;
        if (is_array($pricing) && isset($pricing['input_per_1k'], $pricing['output_per_1k'])) {
            $cost = ($usage['input_tokens'] / 1000) * $pricing['input_per_1k']
                + ($usage['output_tokens'] / 1000) * $pricing['output_per_1k'];
        }
        AiUsageRecord::create([
            'ai_provider_config_id' => $config->id,
            'project_id' => $project->id,
            'profile' => $resolved['profile'] ?? null,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'estimated_cost' => $cost,
            'currency' => $cost !== null ? ($pricing['currency'] ?? 'USD') : null,
        ]);

        return $result;
    }

    /** 25F.2 — test a provider (never logs keys or prompt content). */
    public function testProvider(AiProviderConfig $config): array
    {
        AiNetworkGuard::assertSafeBaseUrl($config);
        try {
            $result = $this->driver($config)->test($config);
        } catch (\Throwable $e) {
            $result = FakeAiDriver::RESULT_PROVIDER_ERROR;
        }
        $config->update(['status' => $result === FakeAiDriver::RESULT_CONNECTED ? 'connected' : 'error']);

        return ['result' => $result];
    }
}
