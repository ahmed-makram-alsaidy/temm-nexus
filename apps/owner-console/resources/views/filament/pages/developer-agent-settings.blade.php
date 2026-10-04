{{--
    Phase 43 — Settings → Developer Agents.

    Runtime configuration: OpenCode (first adapter), managed or external.
    The auth secret input is write-only: mounted empty, never repopulated;
    after save the stored credential is never displayed.
--}}
<x-filament-panels::page>
    @php
        $runtimes = \App\Filament\Pages\DeveloperAgentSettings::runtimes();
        $drivers = \App\Filament\Pages\DeveloperAgentSettings::manager()->catalogue();
        $policyRows = $this->policyRows();
        $managedConfigured = (bool) config('agent.managed_opencode.password');
    @endphp

    {{-- Configured runtimes --}}
    <section class="nx-card" data-nx-inspect="agent-runtimes" data-nx-inspect-label="Agent runtimes">
        <h2 class="nx-card__title">{{ __('agents.runtimes_title') }}</h2>

        @if (count($runtimes) === 0)
            <p class="nx-empty">{{ __('agents.runtimes_empty') }}</p>
        @else
            <div class="nx-stack">
                @foreach ($runtimes as $runtime)
                    <article class="nx-action-card">
                        <h3 class="nx-card__title">
                            {{ $runtime->display_name }}
                            <span class="nx-tag">{{ $runtime->driver }}</span>
                            <span class="nx-tag">{{ __('agents.mode_'.$runtime->mode) }}</span>
                            @if ($runtime->enabled)
                                <span class="nx-status nx-status--success">{{ __('common.enabled') }}</span>
                            @else
                                <span class="nx-status nx-status--neutral">{{ __('common.disabled') }}</span>
                            @endif
                            @if ($runtime->status === 'connected')
                                <span class="nx-status nx-status--success">{{ __('common.status_connected') }}</span>
                            @elseif ($runtime->status === 'error')
                                <span class="nx-status nx-status--danger">{{ __('common.status_error') }}</span>
                            @else
                                <span class="nx-status nx-status--neutral">{{ __('agents.status_untested') }}</span>
                            @endif
                        </h3>
                        <dl class="nx-status-list">
                            <div class="nx-status-list__row">
                                <dt>{{ __('agents.field_endpoint') }}</dt>
                                <dd><span dir="ltr" class="nx-code">{{ $runtime->mode === 'external' ? $runtime->endpoint : config('agent.managed_opencode.endpoint') }}</span></dd>
                            </div>
                            <div class="nx-status-list__row">
                                <dt>{{ __('agents.field_version') }}</dt>
                                <dd><span dir="ltr">{{ $runtime->version ?? __('agents.unknown') }}</span></dd>
                            </div>
                            <div class="nx-status-list__row">
                                <dt>{{ __('agents.field_default_model') }}</dt>
                                <dd><span dir="ltr">{{ $runtime->default_model ?? __('agents.model_runtime_default_none') }}</span></dd>
                            </div>
                            <div class="nx-status-list__row">
                                <dt>{{ __('agents.field_concurrency') }}</dt>
                                <dd>{{ $runtime->max_concurrent_tasks }}</dd>
                            </div>
                            <div class="nx-status-list__row">
                                <dt>{{ __('agents.field_last_test') }}</dt>
                                <dd>
                                    @if ($runtime->last_tested_at)
                                        {{ $runtime->last_tested_at->format('Y-m-d H:i') }}
                                        · {{ $runtime->last_test_status === 'passed' ? __('agents.test_passed') : __('agents.test_failed') }}
                                        @if ($runtime->last_test_message && $runtime->last_test_status !== 'passed')
                                            · {{ $runtime->last_test_message }}
                                        @endif
                                    @else
                                        {{ __('agents.test_never') }}
                                    @endif
                                </dd>
                            </div>
                            @if ($runtime->mode === 'managed')
                                <div class="nx-status-list__row">
                                    <dt>{{ __('agents.managed_secret') }}</dt>
                                    <dd>
                                        @if ($managedConfigured)
                                            <span class="nx-status nx-status--success">{{ __('agents.managed_secret_set') }}</span>
                                        @else
                                            <span class="nx-status nx-status--warning">{{ __('agents.managed_secret_missing') }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endif
                        </dl>
                        <div class="nx-grid--actions">
                            <button type="button" class="nx-btn nx-btn--small" wire:click="editRuntime('{{ $runtime->id }}')">{{ __('agents.edit') }}</button>
                            <button type="button" class="nx-btn nx-btn--small" wire:click="testRuntime('{{ $runtime->id }}')">{{ __('agents.test_connection') }}</button>
                            <button type="button" class="nx-btn nx-btn--small" wire:click="discoverModels('{{ $runtime->id }}')">{{ __('agents.discover_models') }}</button>
                        </div>
                        @if (count($this->discoveredModels) > 0)
                            <p class="nx-hint">{{ __('agents.models_discovered', ['count' => count($this->discoveredModels)]) }}</p>
                            <ul class="nx-list nx-list--compact">
                                @foreach (array_slice($this->discoveredModels, 0, 20) as $model)
                                    <li><span dir="ltr" class="nx-code">{{ $model['canonical'] }}</span></li>
                                @endforeach
                            </ul>
                            @if (count($this->discoveredModels) > 20)
                                <p class="nx-hint">{{ __('agents.models_truncated') }}</p>
                            @endif
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Runtime form --}}
    <section class="nx-card" data-nx-inspect="agent-form" data-nx-inspect-label="Runtime form">
        <h2 class="nx-card__title">{{ $this->runtimeForm['id'] ? __('agents.edit_runtime_title') : __('agents.new_runtime_title') }}</h2>

        <div class="nx-form-grid">
            <div class="nx-field">
                <label for="agent-driver">{{ __('agents.field_driver') }}</label>
                <select id="agent-driver" wire:model.live="runtimeForm.driver">
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver['id'] }}">{{ $driver['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="nx-field">
                <label for="agent-display-name">{{ __('agents.field_display_name') }}</label>
                <input id="agent-display-name" type="text" wire:model="runtimeForm.display_name" />
            </div>
            <div class="nx-field">
                <label for="agent-mode">{{ __('agents.field_mode') }}</label>
                <select id="agent-mode" wire:model.live="runtimeForm.mode">
                    <option value="managed">{{ __('agents.mode_managed') }}</option>
                    <option value="external">{{ __('agents.mode_external') }}</option>
                </select>
                <p class="nx-hint">{{ __('agents.mode_helper') }}</p>
            </div>
            <div class="nx-field">
                <label for="agent-endpoint">{{ __('agents.field_endpoint') }}</label>
                <input id="agent-endpoint" type="text" dir="ltr" wire:model="runtimeForm.endpoint"
                    @if ($this->runtimeForm['mode'] === 'managed') disabled placeholder="{{ config('agent.managed_opencode.endpoint') }}" @endif />
                <p class="nx-hint">{{ __('agents.endpoint_helper') }}</p>
            </div>
            <div class="nx-field">
                <label for="agent-secret">{{ __('agents.field_auth_secret') }}</label>
                <input id="agent-secret" type="password" wire:model="runtimeForm.auth_secret" autocomplete="new-password" />
                <p class="nx-hint">{{ __('agents.auth_secret_helper') }}</p>
            </div>
            <div class="nx-field">
                <label for="agent-default-model">{{ __('agents.field_default_model') }}</label>
                <input id="agent-default-model" type="text" dir="ltr" wire:model="runtimeForm.default_model" placeholder="provider/model" />
                <p class="nx-hint">{{ __('agents.default_model_helper') }}</p>
            </div>
            <div class="nx-field">
                <label for="agent-timeout">{{ __('agents.field_timeout') }}</label>
                <input id="agent-timeout" type="number" min="60" max="21600" wire:model="runtimeForm.timeout_seconds" />
            </div>
            <div class="nx-field">
                <label for="agent-concurrency">{{ __('agents.field_concurrency') }}</label>
                <input id="agent-concurrency" type="number" min="1" max="8" wire:model="runtimeForm.max_concurrent_tasks" />
            </div>
            <div class="nx-field">
                <label for="agent-retention">{{ __('agents.field_retention') }}</label>
                <input id="agent-retention" type="number" min="0" max="90" wire:model="runtimeForm.workspace_retention_days" />
                <p class="nx-hint">{{ __('agents.retention_helper') }}</p>
            </div>
            <div class="nx-field">
                <label class="nx-check">
                    <input type="checkbox" wire:model="runtimeForm.enabled" />
                    <span>{{ __('common.enabled') }}</span>
                </label>
            </div>
        </div>

        @php $validationErrors = $errors->getBag('default'); @endphp
        @if ($validationErrors->any())
            <p class="nx-note nx-note--danger">{{ $validationErrors->first() }}</p>
        @endif

        <div class="nx-grid--actions">
            <button type="button" class="nx-btn nx-btn--primary" wire:click="saveRuntime">{{ __('common.save') }}</button>
            <button type="button" class="nx-btn" wire:click="resetForm">{{ __('common.cancel') }}</button>
        </div>
    </section>

    {{-- Execution policy (transparency, not configuration) --}}
    <section class="nx-card" data-nx-inspect="agent-policy" data-nx-inspect-label="Execution policy">
        <h2 class="nx-card__title">{{ __('agents.policy_title') }}</h2>
        <p class="nx-hint">{{ __('agents.policy_help') }}</p>
        <table class="nx-table">
            <thead>
                <tr>
                    <th>{{ __('agents.policy_column') }}</th>
                    <th>{{ __('agents.verdict_column') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($policyRows as $row)
                    <tr>
                        <td>{{ $row['policy'] }}</td>
                        <td>{{ $row['verdict'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-filament-panels::page>
