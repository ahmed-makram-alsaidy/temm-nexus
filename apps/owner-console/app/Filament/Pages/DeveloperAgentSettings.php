<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Models\AgentRuntime;
use App\Services\Access\Capability;
use App\Services\Agent\AgentExecutionPolicy;
use App\Services\Agent\AgentRuntimeManager;
use App\Services\ControlPlane\AdminAudit;
use App\Services\Agent\Contract\AgentRuntimeException;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * Phase 43 — Settings → Developer Agents.
 *
 * Runtime configuration surface for the agent runtime platform. DISTINCT
 * from Nexus AI settings on purpose: a coding-agent runtime is a different
 * subsystem with a different blast radius.
 *
 * Security posture:
 *   - Page requires Capability::AGENTS_CONFIGURE at PLATFORM scope.
 *   - Auth passwords are write-only (mounted empty, never repopulated) and
 *     stored via the encrypted cast, same as AI provider keys.
 *   - External endpoints are SSRF-guarded on save AND on test.
 *   - Every mutation is audited (AGENT_RUNTIME_*).
 */
class DeveloperAgentSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'developer-agent-settings';

    protected string $view = 'filament.pages.developer-agent-settings';

    /** @var array<string, mixed> create/edit form; `auth_secret` is write-only. */
    public array $runtimeForm = [
        'id' => null,
        'driver' => 'opencode',
        'display_name' => '',
        'mode' => AgentRuntime::MODE_MANAGED,
        'endpoint' => '',
        'auth_secret' => '',
        'default_model' => '',
        'enabled' => false,
        'timeout_seconds' => 1800,
        'max_concurrent_tasks' => 1,
        'workspace_retention_days' => 7,
    ];

    public ?array $testResult = null;

    /** @var list<array<string, mixed>> lazily discovered models for the edited runtime. */
    public array $discoveredModels = [];

    public static function canAccess(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::AGENTS_CONFIGURE);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('agents.nav_settings');
    }

    public function getHeading(): string
    {
        return __('agents.settings_title');
    }

    public function getSubheading(): ?string
    {
        return __('agents.settings_subtitle');
    }

    public static function manager(): AgentRuntimeManager
    {
        return app(AgentRuntimeManager::class);
    }

    public static function runtimes(): array
    {
        return AgentRuntime::query()->orderByDesc('created_at')->get()->all();
    }

    public function mount(): void
    {
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->runtimeForm = [
            'id' => null,
            'driver' => 'opencode',
            'display_name' => '',
            'mode' => AgentRuntime::MODE_MANAGED,
            'endpoint' => '',
            'auth_secret' => '',
            'default_model' => '',
            'enabled' => false,
            'timeout_seconds' => 1800,
            'max_concurrent_tasks' => 1,
            'workspace_retention_days' => 7,
        ];
        $this->discoveredModels = [];
    }

    public function editRuntime(string $id): void
    {
        $runtime = AgentRuntime::find($id);

        if ($runtime === null) {
            return;
        }

        $this->runtimeForm = [
            'id' => $runtime->id,
            'driver' => $runtime->driver,
            'display_name' => $runtime->display_name,
            'mode' => $runtime->mode,
            'endpoint' => (string) ($runtime->endpoint ?? ''),
            'auth_secret' => '', // write-only: never repopulated
            'default_model' => (string) ($runtime->default_model ?? ''),
            'enabled' => (bool) $runtime->enabled,
            'timeout_seconds' => (int) $runtime->timeout_seconds,
            'max_concurrent_tasks' => (int) $runtime->max_concurrent_tasks,
            'workspace_retention_days' => (int) $runtime->workspace_retention_days,
        ];
        $this->discoveredModels = [];
    }

    public function updatedRuntimeFormMode(): void
    {
        // Endpoint only makes sense for external runtimes.
        if ($this->runtimeForm['mode'] === AgentRuntime::MODE_MANAGED) {
            $this->runtimeForm['endpoint'] = '';
        }
    }

    public function saveRuntime(): void
    {
        $data = $this->runtimeForm;
        $drivers = collect(self::manager()->catalogue())->pluck('id')->all();

        $this->validate([
            'runtimeForm.driver' => ['required', 'in:'.implode(',', $drivers)],
            'runtimeForm.display_name' => ['required', 'string', 'max:100'],
            'runtimeForm.mode' => ['required', 'in:'.implode(',', AgentRuntime::MODES)],
            'runtimeForm.endpoint' => ['nullable', 'string', 'max:500'],
            'runtimeForm.default_model' => ['nullable', 'string', 'max:200'],
            'runtimeForm.timeout_seconds' => ['required', 'integer', 'min:60', 'max:21600'],
            'runtimeForm.max_concurrent_tasks' => ['required', 'integer', 'min:1', 'max:8'],
            'runtimeForm.workspace_retention_days' => ['required', 'integer', 'min:0', 'max:90'],
            'runtimeForm.enabled' => ['boolean'],
        ]);

        if ($data['mode'] === AgentRuntime::MODE_EXTERNAL && trim((string) $data['endpoint']) === '') {
            $this->addError('runtimeForm.endpoint', __('agents.endpoint_required'));

            return;
        }

        $attributes = [
            'driver' => $data['driver'],
            'display_name' => $data['display_name'],
            'mode' => $data['mode'],
            'endpoint' => $data['mode'] === AgentRuntime::MODE_EXTERNAL ? trim((string) $data['endpoint']) : null,
            'default_model' => $data['default_model'] !== '' ? $data['default_model'] : null,
            'enabled' => (bool) $data['enabled'],
            'timeout_seconds' => (int) $data['timeout_seconds'],
            'max_concurrent_tasks' => (int) $data['max_concurrent_tasks'],
            'workspace_retention_days' => (int) $data['workspace_retention_days'],
        ];

        // Write-only secret: empty means "keep the stored one".
        if (isset($data['auth_secret']) && is_string($data['auth_secret']) && $data['auth_secret'] !== '') {
            $attributes['auth_secret_encrypted'] = $data['auth_secret'];
        }

        $runtime = isset($data['id']) ? AgentRuntime::find($data['id']) : null;

        if ($runtime !== null) {
            $runtime->update($attributes);
            AdminAudit::record('AGENT_RUNTIME_UPDATED', null, 'agent_runtime', $runtime->id, [
                'driver' => $runtime->driver, 'mode' => $runtime->mode,
            ]);
        } else {
            $runtime = AgentRuntime::create($attributes + ['status' => AgentRuntime::STATUS_UNTESTED]);
            AdminAudit::record('AGENT_RUNTIME_CREATED', null, 'agent_runtime', $runtime->id, [
                'driver' => $runtime->driver, 'mode' => $runtime->mode,
            ]);
        }

        $this->resetForm();
        Notification::make()->title(__('agents.saved'))->success()->send();
    }

    public function testRuntime(string $id): void
    {
        $runtime = AgentRuntime::find($id);

        if ($runtime === null) {
            return;
        }

        $connection = self::manager()->forRuntime($runtime)->testConnection($runtime);

        $runtime->update([
            'status' => $connection->ok ? AgentRuntime::STATUS_CONNECTED : AgentRuntime::STATUS_ERROR,
            'version' => $connection->version ?? $runtime->version,
            'last_tested_at' => now(),
            'last_test_status' => $connection->ok ? 'passed' : 'failed',
            'last_test_message' => $connection->ok ? __('agents.test_passed') : $this->localizedRuntimeError($connection->errorCategory),
            'last_error_category' => $connection->ok ? null : $connection->errorCategory,
        ]);

        AdminAudit::record('AGENT_RUNTIME_TESTED', null, 'agent_runtime', $runtime->id, [
            'ok' => $connection->ok,
            'version' => $connection->version,
            'category' => $connection->ok ? null : $connection->errorCategory,
        ]);

        $this->testResult = $connection->ok
            ? ['ok' => true, 'version' => $connection->version]
            : ['ok' => false, 'category' => $connection->errorCategory, 'message' => $connection->message];

        $connection->ok
            ? Notification::make()->title(__('agents.test_passed').' ('.$connection->version.')')->success()->send()
            : Notification::make()->title(__('agents.test_failed'))->danger()->body($this->localizedRuntimeError($connection->errorCategory))->send();
    }

    public function discoverModels(string $id): void
    {
        $runtime = AgentRuntime::find($id);

        if ($runtime === null) {
            return;
        }

        try {
            $models = self::manager()->forRuntime($runtime)->models($runtime);
            $this->discoveredModels = collect($models)->take(200)->map(fn ($m) => $m->toArray())->all();
        } catch (AgentRuntimeException $e) {
            $this->discoveredModels = [];
            Notification::make()->title(__('agents.models_failed'))->danger()->body($this->localizedRuntimeError($e->category))->send();
        } catch (\Throwable) {
            $this->discoveredModels = [];
            Notification::make()->title(__('agents.models_failed'))->danger()->body(__('agents.error_generic'))->send();
        }
    }

    /** Localized product message for a runtime failure category — never raw exception text. */
    protected function localizedRuntimeError(?string $category): string
    {
        $key = $category === null || $category === '' ? null : 'agents.error_'.mb_strtolower($category);

        if ($key !== null && trans($key) !== $key) {
            return __($key);
        }

        return __('agents.error_generic');
    }

    public function policyRows(): array
    {
        $labels = [
            AgentExecutionPolicy::READ => __('agents.policy_read'),
            AgentExecutionPolicy::WRITE_WORKSPACE => __('agents.policy_write'),
            AgentExecutionPolicy::EXECUTE_WORKSPACE_COMMAND => __('agents.policy_execute'),
            AgentExecutionPolicy::NETWORK_ACCESS => __('agents.policy_network'),
            AgentExecutionPolicy::APPLY_TO_SOURCE => __('agents.policy_apply'),
            AgentExecutionPolicy::DEPLOY => __('agents.policy_deploy'),
        ];

        $rows = [];
        foreach (AgentExecutionPolicy::describe() as $policy => $verdict) {
            $rows[] = [
                'policy' => $labels[$policy] ?? $policy,
                'verdict' => __('agents.verdict_'.$verdict),
            ];
        }

        return $rows;
    }
}
