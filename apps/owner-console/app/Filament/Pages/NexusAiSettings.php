<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use App\Services\Ai\NexusAiConfig;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\Ai\AiGateway;
use App\Services\ControlPlane\Ai\AiNetworkGuard;
use App\Services\ControlPlane\Ai\FakeAiDriver;
use App\Services\ControlPlane\Ai\OpenAiCompatibleDriver;
use App\Services\ControlPlane\SecretVaultService;
use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * 0.4.0-rc.5 (Phase 41, Part A) — Settings → Nexus AI.
 *
 * The production-facing AI configuration surface. A user opening it can
 * immediately see: whether AI is enabled, which provider is configured,
 * which model answers, where the API key goes, whether the connection
 * works, and what scope the settings inherit.
 *
 * Security posture (A.4):
 *   - API keys live ONLY in `ai_provider_configs.secret_encrypted`
 *     (encrypted cast, APP_KEY). The form input is write-only: mounted
 *     empty, never repopulated, and after save the UI shows a masked hint
 *     (SecretVaultService::mask) — never the value.
 *   - No key material reaches notifications, the view, HTML attributes,
 *     or the audit trail (audit records the provider KEY, not secrets).
 *   - Custom base URLs pass AiNetworkGuard (HTTPS, no private/metadata
 *     targets) both on save and on test.
 *
 * Authorization (A.8): the page requires Capability::AI_CONFIGURE at
 * platform scope — PLATFORM_OWNER and PLATFORM_ADMIN only. Projects
 * inherit the platform default; the page shows when a project-scoped
 * provider exists so inheritance is never silent.
 */
class NexusAiSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'nexus-ai-settings';

    protected string $view = 'filament.pages.nexus-ai-settings';

    /** Master AI switch (platform_settings `ai.enabled`). */
    public bool $aiEnabled = true;

    /** Provider form. `api_key` is write-only and always mounted empty. */
    public array $providerForm = [
        'provider' => 'openai',
        'display_name' => '',
        'base_url' => '',
        'model' => '',
        'api_key' => '',
        'timeout_seconds' => 60,
        'max_output_tokens' => 4096,
        'custom_headers' => [],
    ];

    /** Optional per-role model overrides, keyed by ModelRouter role. */
    public array $roleModels = [
        'default' => null,
        'reasoning' => null,
        'code' => null,
    ];

    /** Last safe test outcome for display (never raw provider errors). */
    public ?array $testResult = null;

    public static function canAccess(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AI_CONFIGURE);
    }

    /** Same pattern as NexusAi: only people who may use the page see it. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('ai.nav_settings');
    }

    public function getHeading(): string
    {
        return __('ai.settings_title');
    }

    public function getSubheading(): ?string
    {
        return __('ai.settings_subtitle');
    }

    public function mount(): void
    {
        $this->aiEnabled = NexusAiConfig::aiEnabled();
        $this->loadProviderForm();
        $this->loadRoleModels();
    }

    /** Toggling the master switch persists immediately (visible intent). */
    public function updatedAiEnabled(): void
    {
        NexusAiConfig::setAiEnabled($this->aiEnabled);
        AdminAudit::record(
            'AI_ENABLED_TOGGLED',
            null,
            'platform_setting',
            null,
            ['enabled' => $this->aiEnabled],
        );
        Notification::make()->title(__('ai.enabled_toggled'))
            ->success()->send();
    }

    /** The global (platform-default) row for a provider identifier. */
    public static function globalProvider(string $provider): ?AiProviderConfig
    {
        return NexusAiConfig::scopeVisible(
            AiProviderConfig::query()
                ->whereNull('project_id')
                ->where('provider', $provider)
        )
            ->orderByDesc('id')
            ->first();
    }

    /** Providers the platform has configured globally (fake hidden unless allowed). */
    public static function configuredGlobalProviders(): array
    {
        return NexusAiConfig::scopeVisible(
            AiProviderConfig::query()->whereNull('project_id')
        )
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function saveProvider(): void
    {
        $providers = array_keys(NexusAiConfig::selectableProviders());

        $this->validate([
            'providerForm.provider' => ['required', 'string', 'in:'.implode(',', $providers)],
            'providerForm.display_name' => ['required', 'string', 'max:80'],
            'providerForm.base_url' => ['nullable', 'string', 'max:255', 'url'],
            'providerForm.model' => ['nullable', 'string', 'max:120'],
            'providerForm.api_key' => ['nullable', 'string', 'max:4096'],
            'providerForm.timeout_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'providerForm.max_output_tokens' => ['nullable', 'integer', 'min:64', 'max:200000'],
        ]);

        if (! $this->validateCustomHeaders()) {
            return;
        }

        $provider = $this->providerForm['provider'];
        $existing = self::globalProvider($provider);

        // A key is required only when the provider does not have one yet.
        $hasStoredKey = $existing !== null
            && (string) ($existing->secret_encrypted ?? '') !== '';
        if (! $hasStoredKey && (string) $this->providerForm['api_key'] === '') {
            $this->addError('providerForm.api_key', __('ai.api_key_required'));

            return;
        }

        // SSRF guard for custom base URLs (fake provider is exempt, like AiGateway).
        $probe = new AiProviderConfig([
            'provider' => $provider,
            'base_url' => $this->providerForm['base_url'] ?: null,
        ]);
        if ($provider !== 'fake' && ($probe->base_url ?? null) !== null) {
            try {
                AiNetworkGuard::assertSafeBaseUrl($probe);
            } catch (\Throwable $e) {
                $this->addError('providerForm.base_url', __('ai.test_provider_error'));

                return;
            }
        }

        $config = $existing ?? new AiProviderConfig([
            'provider' => $provider,
            'project_id' => null,
            'enabled' => true,
        ]);

        $config->display_name = $this->providerForm['display_name'];
        $config->base_url = $this->providerForm['base_url'] ?: null;
        $config->model = $this->providerForm['model'] ?: null;
        $config->timeout_seconds = (int) ($this->providerForm['timeout_seconds'] ?: 60);
        $config->max_output_tokens = (int) ($this->providerForm['max_output_tokens'] ?: 4096);
        $config->enabled = true;

        if ((string) $this->providerForm['api_key'] !== '') {
            // Encrypted at rest by the model cast. Never logged, never echoed.
            $config->secret_encrypted = $this->providerForm['api_key'];
        }

        $config->custom_headers = $this->mergeCustomHeaders($existing);

        $config->save();

        AdminAudit::record('AI_PROVIDER_SAVED', null, 'ai_provider_config', $config->getKey(), [
            'provider' => $provider,
            'scope' => 'platform',
            // Header NAMES only — values are credential-class material and
            // are never audited, logged, or displayed.
            'custom_header_names' => array_keys((array) $config->custom_headers),
        ]);

        // Write-only field: wiped after save; UI shows the masked hint only.
        $masked = SecretVaultService::mask($config->secret_encrypted);
        $this->providerForm['api_key'] = '';
        $this->loadProviderForm($provider);
        $this->loadRoleModels($provider);

        Notification::make()
            ->title(__('ai.provider_saved'))
            ->body(__('ai.api_key_saved_mask', ['mask' => $masked]))
            ->success()
            ->send();
    }

    public function saveRouting(): void
    {
        $config = self::globalProvider($this->providerForm['provider']);

        if ($config === null) {
            Notification::make()->title(__('common.status_not_configured'))->danger()->send();

            return;
        }

        foreach (array_keys($this->roleModels) as $role) {
            $model = trim((string) ($this->roleModels[$role] ?? ''));

            $profile = AiModelProfile::query()
                ->where('ai_provider_config_id', $config->getKey())
                ->where('name', $role)
                ->first();

            if ($model === '') {
                $profile?->delete();

                continue;
            }

            AiModelProfile::query()->updateOrCreate(
                ['ai_provider_config_id' => $config->getKey(), 'name' => $role],
                ['model' => $model],
            );
        }

        Notification::make()->title(__('ai.routing_saved'))->success()->send();
    }

    /**
     * A.6 — Test Connection. Runs against the SAVED global row for the
     * selected provider. The result is a safe, localized classification —
     * never a stack trace, never key material.
     */
    public function runTest(): void
    {
        $config = self::globalProvider($this->providerForm['provider']);

        if ($config === null) {
            Notification::make()
                ->title(__('common.status_not_configured'))
                ->body(__('ai.save_provider'))
                ->warning()
                ->send();

            return;
        }

        if (! $config->enabled) {
            $this->testResult = ['ok' => false, 'message' => __('ai.test_disabled')];

            return;
        }

        try {
            AiNetworkGuard::assertSafeBaseUrl($config);
            $result = (new AiGateway)->driver($config)->test($config);
        } catch (\Throwable) {
            $result = FakeAiDriver::RESULT_PROVIDER_ERROR;
        }

        $config->forceFill([
            'status' => $result === FakeAiDriver::RESULT_CONNECTED ? 'connected' : 'error',
            'last_tested_at' => now(),
            'last_test_message' => $this->safeTestMessage($result),
        ])->save();

        $this->testResult = [
            'ok' => $result === FakeAiDriver::RESULT_CONNECTED,
            'message' => $this->safeTestMessage($result),
            'result' => $result,
            'provider' => $config->display_name ?: $config->provider,
            'model' => $config->model,
        ];
    }

    /** Present the resolved routing table (what answers each role, and why). */
    public function routingTable(): array
    {
        return (new \App\Services\Ai\ModelRouter)->routingTable();
    }

    public function routingRoleLabel(string $role): string
    {
        return match ($role) {
            \App\Services\Ai\ModelRouter::ROLE_DEFAULT => __('ai.role_default'),
            \App\Services\Ai\ModelRouter::ROLE_REASONING => __('ai.role_reasoning'),
            \App\Services\Ai\ModelRouter::ROLE_CODE => __('ai.role_code'),
            default => ucfirst($role),
        };
    }

    /** Masked hint of the stored key — never the value itself (A.4). */
    public function maskedKey(?AiProviderConfig $config = null): ?string
    {
        $config ??= self::globalProvider($this->providerForm['provider']);
        $secret = (string) ($config->secret_encrypted ?? '');

        return $secret === '' ? null : SecretVaultService::mask($secret);
    }

    public function projectScopedProviderCount(): int
    {
        return (int) AiProviderConfig::query()->whereNotNull('project_id')->count();
    }

    public function providerOptions(): array
    {
        return NexusAiConfig::selectableProviders();
    }

    // ── Custom HTTP headers (rc.7) ──────────────────────────────────────

    /**
     * Strict per-row validation of the custom header map. Returns false and
     * attaches per-field errors when anything is off; the save then aborts.
     */
    protected function validateCustomHeaders(): bool
    {
        $rows = array_values((array) ($this->providerForm['custom_headers'] ?? []));

        if (count($rows) > 8) {
            $this->addError('providerForm.custom_headers', __('ai.header_too_many'));

            return false;
        }

        $seen = [];
        foreach ($rows as $i => $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $value = (string) ($row['value'] ?? '');

            if ($name === '') {
                continue; // empty rows are ignored at save, not an error
            }

            if (! preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name)) {
                $this->addError("providerForm.custom_headers.{$i}.name", __('ai.header_name_invalid'));

                return false;
            }

            $lower = strtolower($name);
            if (in_array($lower, OpenAiCompatibleDriver::FORBIDDEN_CUSTOM_HEADERS, true)) {
                $this->addError("providerForm.custom_headers.{$i}.name", __('ai.header_name_invalid'));

                return false;
            }

            if (isset($seen[$lower])) {
                $this->addError("providerForm.custom_headers.{$i}.name", __('ai.header_name_invalid'));

                return false;
            }
            $seen[$lower] = true;

            if ($value !== '' && (strlen($value) > 512 || preg_match('/[\r\n]/', $value))) {
                $this->addError("providerForm.custom_headers.{$i}.value", __('ai.header_value_invalid'));

                return false;
            }
        }

        return true;
    }

    /**
     * Build the stored map from the form. Values are write-only: a row whose
     * value is left blank KEEPS the stored value for that header (the same
     * posture as the API key); a blank-name row is dropped.
     *
     * @return array<string, string>
     */
    protected function mergeCustomHeaders(?AiProviderConfig $existing): array
    {
        $stored = (array) ($existing?->custom_headers ?? []);
        $map = [];

        foreach (array_values((array) ($this->providerForm['custom_headers'] ?? [])) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $value = (string) ($row['value'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($value !== '') {
                $map[$name] = $value;
            } elseif (array_key_exists($name, $stored)) {
                $map[$name] = (string) $stored[$name];
            }
        }

        return $map;
    }

    /** Add an empty custom header row to the form. */
    public function addCustomHeader(): void
    {
        if (count($this->providerForm['custom_headers']) >= 8) {
            return;
        }

        $this->providerForm['custom_headers'][] = ['name' => '', 'value' => ''];
    }

    /** Remove one custom header row (removal deletes the stored header on save). */
    public function removeCustomHeader(int $index): void
    {
        unset($this->providerForm['custom_headers'][$index]);
        $this->providerForm['custom_headers'] = array_values($this->providerForm['custom_headers']);
    }

    /**
     * AgentRouter compatibility preset for the OpenAI-compatible provider.
     * Fills the gateway base URL, the recommended model and the required
     * client identification header. The generic custom-header mechanism
     * remains the source of truth — this only prefills the form.
     */
    public function applyAgentRouterPreset(): void
    {
        if ($this->providerForm['provider'] !== 'openai_compatible') {
            Notification::make()->title(__('ai.preset_platform_only'))->warning()->send();

            return;
        }

        $genericName = NexusAiConfig::selectableProviders()['openai_compatible'] ?? 'OpenAI-compatible';
        if (trim($this->providerForm['display_name']) === '' || $this->providerForm['display_name'] === $genericName) {
            $this->providerForm['display_name'] = 'AgentRouter';
        }
        $this->providerForm['base_url'] = 'https://agentrouter.org/v1';

        if (trim((string) $this->providerForm['model']) === '') {
            $this->providerForm['model'] = 'deepseek-v4-flash';
        }

        foreach ($this->providerForm['custom_headers'] as $row) {
            if (strcasecmp(trim((string) ($row['name'] ?? '')), 'User-Agent') === 0) {
                Notification::make()->title(__('ai.preset_agentrouter_applied'))->success()->send();

                return;
            }
        }

        $this->providerForm['custom_headers'][] = [
            'name' => 'User-Agent',
            'value' => 'codex_cli_rs/0.149.1',
        ];

        Notification::make()->title(__('ai.preset_agentrouter_applied'))->success()->send();
    }

    /** ── Internals ──────────────────────────────────────────────────── */

    protected function safeTestMessage(string $result): string
    {
        return match ($result) {
            FakeAiDriver::RESULT_CONNECTED => __('ai.test_connected'),
            FakeAiDriver::RESULT_AUTH_FAILED => __('ai.test_auth_failed'),
            FakeAiDriver::RESULT_MODEL_NOT_FOUND => __('ai.test_model_not_found'),
            FakeAiDriver::RESULT_RATE_LIMITED => __('ai.test_rate_limited'),
            FakeAiDriver::RESULT_TIMEOUT => __('ai.test_timeout'),
            default => __('ai.test_provider_error'),
        };
    }

    protected function loadProviderForm(?string $provider = null): void
    {
        $provider ??= $this->providerForm['provider'] ?? 'openai';

        $this->providerForm['provider'] = $provider;
        $this->providerForm['api_key'] = '';

        $config = self::globalProvider($provider);

        if ($config === null) {
            $this->providerForm['display_name'] = $this->providerOptions()[$provider] ?? $provider;
            $this->providerForm['base_url'] = '';
            $this->providerForm['model'] = '';
            $this->providerForm['timeout_seconds'] = 60;
            $this->providerForm['max_output_tokens'] = 4096;
            $this->providerForm['custom_headers'] = [];

            return;
        }

        $this->providerForm['display_name'] = (string) ($config->display_name ?? '');
        // Base URL is configuration, not a secret — safe to repopulate.
        $this->providerForm['base_url'] = (string) ($config->base_url ?? '');
        $this->providerForm['model'] = (string) ($config->model ?? '');
        $this->providerForm['timeout_seconds'] = (int) ($config->timeout_seconds ?? 60);
        $this->providerForm['max_output_tokens'] = (int) ($config->max_output_tokens ?? 4096);

        // Header NAMES repopulate; values are write-only credential-class
        // material and never travel back to the browser. A row left with an
        // empty value keeps the stored value on save.
        $this->providerForm['custom_headers'] = array_values(array_map(
            fn (string $name): array => ['name' => $name, 'value' => ''],
            array_keys((array) ($config->custom_headers ?? []))
        ));
    }

    protected function loadRoleModels(?string $provider = null): void
    {
        $config = self::globalProvider($provider ?? $this->providerForm['provider']);

        foreach (array_keys($this->roleModels) as $role) {
            $this->roleModels[$role] = null;

            if ($config === null) {
                continue;
            }

            $profile = AiModelProfile::query()
                ->where('ai_provider_config_id', $config->getKey())
                ->where('name', $role)
                ->first();

            $this->roleModels[$role] = $profile?->model;
        }
    }

    /** Live-update the form (and role overrides) when the provider changes. */
    public function updatedProviderFormProvider(): void
    {
        $this->loadProviderForm($this->providerForm['provider']);
        $this->loadRoleModels($this->providerForm['provider']);
    }
}
