{{--
    0.4.0-rc.5 (Phase 41, Part A) — Settings → Nexus AI.

    Answers at a glance (mission A): is AI enabled, which provider, which
    model, where does the key go, does the connection work, what is
    inherited. The API key input is write-only: mounted empty, never
    repopulated; after save only a masked hint is shown.
--}}
<x-filament-panels::page>
    @php
        $global = \App\Filament\Pages\NexusAiSettings::configuredGlobalProviders();
        $current = \App\Filament\Pages\NexusAiSettings::globalProvider($this->providerForm['provider']);
        $masked = $this->maskedKey($current);
        $router = new \App\Services\Ai\ModelRouter;
        $configured = $router->isConfigured();
    @endphp

    {{-- Current status (A.3) --}}
    <div class="nx-settings-grid">
        <section class="nx-card" data-nx-inspect="ai.status" data-nx-inspect-label="AI status">
            <h2 class="nx-card__title">{{ __('ai.status_title') }}</h2>

            <dl class="nx-status-list">
                <div class="nx-status-list__row">
                    <dt>{{ __('common.status') }}</dt>
                    <dd>
                        @if (! $this->aiEnabled)
                            <span class="nx-status nx-status--danger">{{ __('common.disabled') }}</span>
                        @elseif ($configured)
                            <span class="nx-status nx-status--success">{{ __('common.status_ready') }}</span>
                        @else
                            <span class="nx-status nx-status--warning">{{ __('common.status_not_configured') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('ai.status_provider') }}</dt>
                    <dd>{{ $current?->display_name ?? ($current?->provider ?? __('common.none')) }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('ai.status_model') }}</dt>
                    <dd>{{ $current?->model ?? __('common.none') }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('ai.status_last_test') }}</dt>
                    <dd>
                        @if ($current?->last_tested_at)
                            {{ $current->last_tested_at->format('Y-m-d H:i') }}
                            @if ($current->status === 'connected')
                                · <span class="nx-status nx-status--success">{{ __('common.status_connected') }}</span>
                            @else
                                · <span class="nx-status nx-status--danger">{{ __('common.status_error') }}</span>
                            @endif
                        @else
                            {{ __('ai.status_last_test_never') }}
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('ai.status_last_error') }}</dt>
                    <dd>{{ $current?->last_test_message ?? __('ai.status_none_recorded') }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('ai.status_scope') }}</dt>
                    <dd>
                        <span class="nx-tag">{{ __('ai.status_scope_platform') }}</span>
                        @if ($this->projectScopedProviderCount() > 0)
                            <p class="nx-hint">{{ __('ai.status_scope_project', ['count' => $this->projectScopedProviderCount()]) }}</p>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Master switch --}}
        <section class="nx-card" data-nx-inspect="ai.master" data-nx-inspect-label="AI enabled">
            <h2 class="nx-card__title">{{ __('ai.ai_master_enabled') }}</h2>
            <label class="nx-check nx-check--wide">
                <input type="checkbox" wire:model.live="aiEnabled" />
                <span>{{ __('common.enabled') }}</span>
            </label>
            <p class="nx-hint">{{ __('ai.ai_master_helper') }}</p>
        </section>
    </div>

    {{-- Provider configuration (A.2/A.3) --}}
    <section class="nx-card" data-nx-inspect="ai.provider" data-nx-inspect-label="AI provider">
        <h2 class="nx-card__title">{{ __('ai.provider_section_title') }}</h2>
        <p class="nx-hint">{{ __('ai.provider_section_help') }}</p>

        <div class="nx-form-grid">
            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.provider') }}</span>
                <select wire:model.live="providerForm.provider" class="nx-field__input">
                    @foreach ($this->providerOptions() as $key => $label)
                        <option value="{{ $key }}" @selected($this->providerForm['provider'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.display_name') }}</span>
                <input type="text" wire:model="providerForm.display_name" class="nx-field__input" maxlength="80" />
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.base_url') }}</span>
                <input type="url" wire:model="providerForm.base_url" class="nx-field__input"
                       placeholder="https://" @if (in_array($this->providerForm['provider'], (array) config('nexus-ai.requires_base_url', []))) required @endif />
                <span class="nx-field__hint">{{ __('ai.base_url_helper') }}</span>
                @error('providerForm.base_url')<span class="nx-field__error">{{ $message }}</span>@enderror
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.default_model') }}</span>
                <input type="text" wire:model="providerForm.model" class="nx-field__input"
                       placeholder="gpt-4.1-mini / claude-sonnet-4 / gemini-2.0-flash" />
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.api_key') }}</span>
                {{-- Write-only. Never carries the stored value back to the browser. --}}
                <input type="password" wire:model="providerForm.api_key" class="nx-field__input"
                       autocomplete="new-password" value="" />
                @if ($masked)
                    <span class="nx-field__hint">{{ __('ai.api_key_saved_mask', ['mask' => $masked]) }}</span>
                @else
                    <span class="nx-field__hint">{{ __('ai.api_key_helper') }}</span>
                @endif
                @error('providerForm.api_key')<span class="nx-field__error">{{ $message }}</span>@enderror
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.timeout') }}</span>
                <input type="number" wire:model="providerForm.timeout_seconds" class="nx-field__input" min="5" max="600" />
            </label>

            <label class="nx-field">
                <span class="nx-field__label">{{ __('ai.max_output_tokens') }}</span>
                <input type="number" wire:model="providerForm.max_output_tokens" class="nx-field__input" min="64" max="200000" />
            </label>
        </div>

        {{-- rc.7 — Advanced: custom HTTP headers (client identification). --}}
        <details class="nx-advanced" @if (! empty($this->providerForm['custom_headers'])) open @endif>
            <summary class="nx-advanced__summary">{{ __('ai.custom_headers_section') }}</summary>
            <p class="nx-hint">{{ __('ai.custom_headers_help') }}</p>

            @if ($this->providerForm['provider'] === 'openai_compatible')
                <div class="nx-card__actions">
                    <button type="button" wire:click="applyAgentRouterPreset" class="nx-btn nx-btn--small">{{ __('ai.preset_agentrouter') }}</button>
                </div>
            @endif

            @foreach ($this->providerForm['custom_headers'] as $i => $row)
                <div class="nx-header-row">
                    <label class="nx-field">
                        <span class="nx-field__label">{{ __('ai.header_name') }}</span>
                        <input type="text" wire:model="providerForm.custom_headers.{{ $i }}.name"
                               class="nx-field__input" maxlength="64" placeholder="User-Agent" />
                        @error('providerForm.custom_headers.'.$i.'.name')<span class="nx-field__error">{{ $message }}</span>@enderror
                    </label>
                    <label class="nx-field">
                        <span class="nx-field__label">{{ __('ai.header_value') }}</span>
                        {{-- Write-only. Never carries the stored value back to the browser. --}}
                        <input type="password" wire:model="providerForm.custom_headers.{{ $i }}.value"
                               class="nx-field__input" autocomplete="new-password" value="" />
                        <span class="nx-field__hint">{{ __('ai.header_value_saved') }}</span>
                        @error('providerForm.custom_headers.'.$i.'.value')<span class="nx-field__error">{{ $message }}</span>@enderror
                    </label>
                    <button type="button" wire:click="removeCustomHeader({{ $i }})" class="nx-btn nx-btn--small"
                            title="{{ __('ai.header_remove') }}">✕</button>
                </div>
            @endforeach

            @error('providerForm.custom_headers')<span class="nx-field__error">{{ $message }}</span>@enderror

            <div class="nx-card__actions">
                <button type="button" wire:click="addCustomHeader" class="nx-btn nx-btn--small">+ {{ __('ai.header_add') }}</button>
            </div>
        </details>

        <div class="nx-card__actions">
            <button type="button" wire:click="saveProvider" class="nx-btn nx-btn--primary">{{ __('ai.save_provider') }}</button>
            <button type="button" wire:click="runTest" class="nx-btn">{{ __('ai.test_button') }}</button>
        </div>

        {{-- Test connection result (A.6) — safe message only. --}}
        @if ($this->testResult)
            <div class="nx-note {{ $this->testResult['ok'] ? 'nx-note--success' : 'nx-note--danger' }}" role="status">
                <strong>{{ $this->testResult['ok'] ? __('ai.test_ok') : __('common.connection_failed') }}</strong>
                <span>{{ $this->testResult['message'] }}</span>
                @if ($this->testResult['ok'])
                    <span>{{ __('ai.test_result_provider', ['provider' => $this->testResult['provider']]) }}</span>
                    @if (! empty($this->testResult['model']))
                        <span>{{ __('ai.test_result_model', ['model' => $this->testResult['model']]) }}</span>
                    @endif
                @endif
            </div>
        @endif
    </section>

    {{-- Model routing UX (A.5) — optional role overrides + inheritance display --}}
    <section class="nx-card" data-nx-inspect="ai.routing" data-nx-inspect-label="Model routing">
        <h2 class="nx-card__title">{{ __('ai.routing_section_title') }}</h2>
        <p class="nx-hint">{{ __('ai.routing_section_help') }}</p>

        <div class="nx-form-grid">
            @foreach (['default', 'reasoning', 'code'] as $role)
                <label class="nx-field">
                    <span class="nx-field__label">{{ $this->routingRoleLabel($role) }}</span>
                    <input type="text" wire:model="roleModels.{{ $role }}" class="nx-field__input"
                           placeholder="{{ __('ai.routing_inherit') }}" />
                </label>
            @endforeach
        </div>

        <table class="nx-table">
            <thead>
                <tr>
                    <th>{{ __('ai.model_routing') }}</th>
                    <th>{{ __('ai.status_provider') }}</th>
                    <th>{{ __('ai.status_model') }}</th>
                    <th>{{ __('common.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->routingTable() as $row)
                    <tr>
                        <td>{{ $this->routingRoleLabel($row['role']) }}</td>
                        <td>{{ $row['provider'] ?? __('common.none') }}</td>
                        <td>{{ $row['model'] ?? __('ai.routing_inherit') }}</td>
                        <td>{{ __('ai.routing_source_'.match ($row['source'] ?? '') {
                            'provider_default' => 'provider_default',
                            'fallback_provider' => 'fallback',
                            default => str_starts_with((string) ($row['source'] ?? ''), 'profile:') ? 'profile' : 'unconfigured',
                        }) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="nx-card__actions">
            <button type="button" wire:click="saveRouting" class="nx-btn nx-btn--primary">{{ __('common.save') }}</button>
        </div>
    </section>

    @if (\App\Services\Ai\NexusAiConfig::fakeProvidersAllowed())
        <p class="nx-hint">{{ __('ai.fake_provider_note') }}</p>
    @endif
</x-filament-panels::page>
