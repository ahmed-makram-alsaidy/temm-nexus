{{--
    0.4.0-rc.5 (Phase 41, Part B) — New Project wizard.

    Six guided steps. Product language first: "Live Sync", "Last synced",
    "Connection" — engine internals stay hidden. Secrets entered here go
    straight to the vault and are never echoed back by Livewire.
--}}
<x-filament-panels::page>
    @php
        $total = count(\App\Filament\Pages\NewProjectWizard::STEPS);
        $workspaces = $this->accessibleWorkspaces();
        $fields = $this->connectionFields();
        $connector = $this->connector();
        $created = $this->projectId !== null;
    @endphp

    {{-- Progress (B.1) --}}
    <ol class="nxw-steps" aria-label="{{ __('wizard.title') }}">
        @foreach (\App\Filament\Pages\NewProjectWizard::STEPS as $i => $key)
            <li @class([
                'nxw-steps__item',
                'is-now' => $this->step === $i + 1,
                'is-done' => $this->step > $i + 1,
            ])>
                <span class="nxw-steps__n">{{ $i + 1 }}</span>
                <span class="nxw-steps__label">{{ __('wizard.step_'.$key) }}</span>
            </li>
        @endforeach
    </ol>

    <p class="nx-hint">{{ __('wizard.step_of', ['current' => $this->step, 'total' => $total]) }}</p>

    @if ($this->error)
        {{-- 0.6.0 Phase A (§A7): never a bare "Error" — what failed, what to
             do next, and the underlying message only behind a disclosure. --}}
        <div class="nx-error" role="alert">
            <span class="nx-error__icon" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
            </span>
            <div class="nx-error__content">
                <p class="nx-error__title">{{ __('foundation.wizard_step_failed_title') }}</p>
                <p class="nx-error__body">{{ $this->error }}</p>
                @if ($this->errorDetail)
                    <div class="nx-error__actions">
                        <details class="nx-details nx-details--inline">
                            <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                            <div class="nx-details__body" dir="ltr">
                                <code class="nx-tech">{{ $this->errorDetail }}</code>
                            </div>
                        </details>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Step 1 — Project (B.2) --}}
    @if ($this->step === 1)
        <section class="nx-card">
            <div class="nx-form-grid">
                <label class="nx-field">
                    <span class="nx-field__label">{{ __('wizard.project_name') }}</span>
                    <input type="text" wire:model="state.name" class="nx-field__input" maxlength="128" />
                    <span class="nx-field__hint">{{ __('wizard.project_name_helper') }}</span>
                </label>

                <label class="nx-field">
                    <span class="nx-field__label">{{ __('wizard.workspace_client') }}</span>
                    <select wire:model="state.workspace_id" class="nx-field__input">
                        <option value="">—</option>
                        @foreach ($workspaces as $id => $name)
                            <option value="{{ $id }}" @selected((string) $this->state['workspace_id'] === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <span class="nx-field__hint">{{ __('wizard.workspace_client_helper') }}</span>
                    @error('state.workspace_id')<span class="nx-field__error">{{ $message }}</span>@enderror
                </label>

                <div class="nx-field">
                    <span class="nx-field__label">{{ __('wizard.environment') }}</span>
                    <div class="nxw-cards nxw-cards--choices">
                        @foreach (\App\Filament\Pages\NewProjectWizard::ENVIRONMENTS as $env)
                            <button type="button" wire:click="$set('state.environment', '{{ $env }}')"
                                    class="nxw-card--choice @if (($this->state['environment'] ?? '') === $env) is-selected @endif">
                                <strong>{{ __('common.env_'.$env) }}</strong>
                            </button>
                        @endforeach
                    </div>
                    @if (($this->state['environment'] ?? '') === 'production')
                        <span class="nx-field__hint">{{ __('wizard.env_helper_production') }}</span>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Step 2 — Source cards (B.3) --}}
    @if ($this->step === 2)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.source_pick') }}</h2>
            <p class="nx-hint">{{ __('wizard.source_pick_helper') }}</p>

            <div class="nxw-cards nxw-cards--sources">
                @foreach ($this->sourceCards() as $card)
                    <button type="button" wire:click="$set('state.connector', '{{ $card['key'] }}')"
                            class="nxw-card--source @if ($card['selected']) is-selected @endif"
                            data-connector="{{ $card['key'] }}">
                        <strong class="nxw-card--source__name">{{ $card['name'] }}</strong>
                        <span class="nxw-card--source__desc">{{ $card['description'] }}</span>
                        <span class="nxw-card--source__caps">
                            @if ($card['migration'])
                                <span class="nx-tag">{{ __('wizard.source_migration') }}</span>
                            @endif
                            @if ($card['live_sync'])
                                <span class="nx-tag nx-tag--info">{{ __('wizard.source_live_sync') }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
            @error('state.connector')<span class="nx-field__error">{{ $message }}</span>@enderror
        </section>
    @endif

    {{-- Step 3 — Connection (B.4/B.5) --}}
    @if ($this->step === 3)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.connection_title', ['name' => $this->connectorName()]) }}</h2>
            <p class="nx-hint">{{ __('wizard.connection_helper') }}</p>

            @if ($fields === [])
                <p class="nx-hint">{{ __('wizard.no_fields_needed') }}</p>
            @else
                <div class="nx-form-grid">
                    @foreach ($fields as $field)
                        <label class="nx-field">
                            <span class="nx-field__label">{{ $field->label }}@if ($field->required) * @endif</span>
                            @if ($field->type === 'select' && $field->options !== null)
                                <select wire:model="state.connection.{{ $field->key }}" class="nx-field__input">
                                    @foreach ($field->options as $value => $label)
                                        <option value="{{ $value }}" @selected(($this->state['connection'][$field->key] ?? $field->default) == $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @elseif ($field->type === 'boolean')
                                <label class="nx-check">
                                    <input type="checkbox" wire:model="state.connection.{{ $field->key }}"
                                           @checked(($this->state['connection'][$field->key] ?? $field->default) == true) />
                                    <span>{{ $field->label }}</span>
                                </label>
                            @elseif ($field->type === 'port')
                                <input type="number" wire:model="state.connection.{{ $field->key }}" class="nx-field__input"
                                       value="{{ $this->state['connection'][$field->key] ?? $field->default }}" />
                            @elseif ($field->secret)
                                {{-- Write-only: the value lives in the vault after save. --}}
                                <input type="password" wire:model="state.connection.{{ $field->key }}" class="nx-field__input"
                                       autocomplete="new-password" />
                            @else
                                <input type="text" wire:model="state.connection.{{ $field->key }}" class="nx-field__input"
                                       value="{{ $this->state['connection'][$field->key] ?? $field->default }}" />
                            @endif
                            @if ($field->help)
                                <span class="nx-field__hint">{{ $field->help }}</span>
                            @endif
                            @error('state.connection.'.$field->key)<span class="nx-field__error">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                </div>
            @endif

            <p class="nx-hint">{{ __('wizard.secrets_note') }}</p>

            <div class="nx-card__actions">
                <button type="button" wire:click="testConnection" wire:loading.attr="disabled"
                        class="nx-btn nx-btn--primary" data-wizard-test-connection>
                    {{ __('common.test_connection') }}
                </button>
                <span wire:loading wire:target="testConnection" class="nx-hint">{{ __('wizard.test_running') }}</span>
            </div>

            @if ($this->testResult)
                <div class="nx-note {{ ($this->testResult['ok'] ?? false) ? 'nx-note--success' : 'nx-note--danger' }}" role="status"
                     data-wizard-test-result>
                    <strong>{{ ($this->testResult['ok'] ?? false) ? __('wizard.test_passed') : __('wizard.test_failed') }}</strong>
                    @if (($this->testResult['detail'] ?? '') !== '')
                        <span>{{ $this->testResult['detail'] }}</span>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- Step 4 — Destination (B.6) — TEMM-managed is the obvious default --}}
    @if ($this->step === 4)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.destination_title') }}</h2>

            <div class="nxw-cards nxw-cards--choices">
                <button type="button" wire:click="$set('state.destination', 'temm')"
                        class="nxw-card--choice @if (($this->state['destination'] ?? 'temm') === 'temm') is-selected @endif">
                    <strong>{{ __('wizard.destination_temm') }}</strong>
                    <span>{{ __('wizard.destination_temm_helper') }}</span>
                </button>
                <button type="button" wire:click="$set('state.destination', 'external')"
                        class="nxw-card--choice @if (($this->state['destination'] ?? '') === 'external') is-selected @endif">
                    <strong>{{ __('wizard.destination_external') }}</strong>
                    <span>{{ __('wizard.destination_external_helper') }}</span>
                </button>
            </div>

            {{-- Advanced — collapsed by default (B.4/B.10) --}}
            @if (($this->state['destination'] ?? 'temm') === 'external')
                <details class="nx-advanced" data-wizard-advanced open>
                    <summary>{{ __('common.advanced_options') }}</summary>
                    <div class="nx-form-grid">
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('wizard.target_host') }}</span>
                            <input type="text" wire:model="state.target.host" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('wizard.target_port') }}</span>
                            <input type="number" wire:model="state.target.port" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('wizard.target_database') }}</span>
                            <input type="text" wire:model="state.target.database" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('wizard.target_username') }}</span>
                            <input type="text" wire:model="state.target.username" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('wizard.target_password') }}</span>
                            <input type="password" wire:model="state.target.password" class="nx-field__input" autocomplete="new-password" />
                            <span class="nx-field__hint">{{ __('wizard.target_password_helper') }}</span>
                        </label>
                        <label class="nx-check">
                            <input type="checkbox" wire:model="state.target_disposable" />
                            <span>{{ __('wizard.target_disposable') }}</span>
                        </label>
                    </div>
                </details>
                <p class="nx-hint">{{ __('wizard.destination_note') }}</p>
            @endif
        </section>
    @endif

    {{-- Step 5 — Analyze (B.7) --}}
    @if ($this->step === 5)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.analyze_title', ['name' => $this->state['name']]) }}</h2>
            <p class="nx-hint">{{ __('wizard.analyze_helper') }}</p>

            <div class="nx-card__actions">
                <button type="button" wire:click="analyze" wire:loading.attr="disabled" class="nx-btn nx-btn--primary"
                        data-wizard-analyze>
                    {{ __('wizard.analyze_button') }}
                </button>
                <span wire:loading wire:target="analyze" class="nx-hint">{{ __('wizard.analyze_running') }}</span>
            </div>

            @if ($this->analysisSummary !== null)
                <h3 class="nx-card__subtitle">{{ __('wizard.analyze_summary') }}</h3>
                <div class="nx-grid nx-grid--stats" data-wizard-analysis-summary>
                    @foreach (['tables', 'views', 'auth', 'storage', 'functions', 'triggers', 'policies', 'realtime'] as $key)
                        @if (($this->analysisSummary[$key] ?? 0) > 0)
                            <div class="nx-stat-card">
                                <span class="nx-stat-card__label">{{ __('wizard.analyze_counts_'.$key) }}</span>
                                <span class="nx-stat-card__value">{{ $this->analysisSummary[$key] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
                <p class="nx-hint">{{ __('wizard.analyze_done') }} {{ __('wizard.analyze_issues_none') }}</p>
            @endif
        </section>
    @endif

    {{-- Step 6 — Review (B.8) --}}
    @if ($this->step === 6)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.review_title') }}</h2>
            <p class="nx-hint">{{ __('wizard.review_helper') }}</p>

            <dl class="nx-status-list" data-wizard-review>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_database') }}</dt>
                    <dd><span class="nx-status nx-status--success">{{ __('wizard.review_ready') }}</span>
                        {{ __('wizard.analyze_counts_tables') }}: {{ $this->analysisSummary['tables'] ?? 0 }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_users') }}</dt>
                    <dd><span class="nx-status nx-status--success">{{ __('wizard.review_ready') }}</span>
                        {{ $this->analysisSummary['auth'] ?? 0 }}</dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_storage') }}</dt>
                    <dd>
                        @if (($this->analysisSummary['storage'] ?? 0) > 0)
                            <span class="nx-status nx-status--success">{{ __('wizard.review_ready') }}</span>
                        @else
                            <span class="nx-status nx-status--warning">{{ __('wizard.review_needs_review') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_live_sync') }}</dt>
                    <dd>
                        @if ($this->selectedConnectorSupportsLiveSync())
                            <span class="nx-status nx-status--success">{{ __('wizard.review_supported') }}</span>
                        @else
                            <span class="nx-status nx-status--neutral">{{ __('wizard.review_not_supported') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_client_code') }}</dt>
                    <dd>
                        <span class="nx-status nx-status--neutral">{{ __('common.status_unknown') }}</span>
                        <span class="nx-hint">{{ __('wizard.review_scan_hint') }}</span>
                    </dd>
                </div>
            </dl>

            @if ($this->planSummary !== null)
                <p class="nx-hint">{{ trans_choice('wizard.review_plan_items', $this->planSummary['items'], ['count' => $this->planSummary['items']]) }}</p>
            @endif

            <div class="nx-card__actions">
                <button type="button" wire:click="startMigration" class="nx-btn nx-btn--primary" data-wizard-start-migration>
                    {{ __('wizard.start_migration') }}
                </button>
                <span class="nx-hint">{{ __('wizard.start_migration_helper') }}</span>
                @if ($this->projectId !== null)
                    <a class="nx-link" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $this->project()]) }}">
                        {{ __('wizard.open_migration_center') }}
                    </a>
                @endif
            </div>
        </section>
    @endif

    {{-- Wizard footer: Back / Continue + AI help (B.9) --}}
    <div class="nx-wizard-footer">
        @if ($this->step > 1)
            <button type="button" wire:click="back" class="nx-btn">{{ __('common.back') }}</button>
        @endif
        @if ($this->step < $total)
            <button type="button" wire:click="continue" class="nx-btn nx-btn--primary">{{ __('common.continue') }}</button>
        @endif
        @if ($created)
            <span class="nx-hint">{{ __('wizard.created_note') }}</span>
        @endif
        <a class="nx-link nx-ai-help" href="{{ $this->aiHelpUrl() }}">{{ __('wizard.not_sure_ask_nexus_ai') }}</a>
    </div>
</x-filament-panels::page>
