{{--
    0.6.0 Phase E — New Project wizard: ONE journey in five steps.

    §E3  State persists server-side from the first step; a resumed draft
         announces itself and can be discarded explicitly.
    §E2  The workspace dead end is gone — create a workspace inline and
         stay in the wizard with every entered field intact.
    §E4  Host → Port → Database → Username → Password; expert fields live
         behind Advanced; connector cards are one sentence + badges.
    §E5  Connection outcomes are classified: success / credentials /
         unreachable / private network — with a plain-language recovery
         path and Technical details for the raw diagnostics.
    §E7  Analysis runs in real recorded stages, ticked from the UI; no
         fake percentages, no frozen form.
    §E10 Review shows deterministic readiness — never green over nothing.
--}}
<x-filament-panels::page>
    @php
        $total = count(\App\Filament\Pages\NewProjectWizard::STEPS);
        $workspaces = $this->accessibleWorkspaces();
        $groups = $this->connectionFieldGroups();
        $connector = $this->connector();
        $created = $this->projectId !== null;
        $analysis = $this->analysis();
        $progress = $this->analysisProgress();
        $summary = $this->analysisSummary();
        $readiness = $this->reviewReadiness();
        $warnings = $summary['warnings'] ?? [];
        $blockers = $this->reviewBlockers();
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

    @if ($this->resumed)
        <div class="nx-note nx-note--info" role="status" data-wizard-resumed>
            <strong>{{ __('wizard.resumed_title') }}</strong>
            <span>{{ __('wizard.resumed_body') }}</span>
            <button type="button" wire:click="discardDraft" wire:confirm="{{ __('wizard.discard_confirm') }}"
                    class="nx-link" data-wizard-start-over>{{ __('wizard.discard_draft') }}</button>
        </div>
    @endif

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

    {{-- Step 1 — Project (§E2/§E3): name, workspace with inline create, environment --}}
    @if ($this->step === 1)
        <section class="nx-card">
            <div class="nx-form-grid">
                <label class="nx-field">
                    <span class="nx-field__label">{{ __('wizard.project_name') }}</span>
                    <input type="text" wire:model.live.debounce.600ms="state.name" class="nx-field__input" maxlength="128" />
                    <span class="nx-field__hint">{{ __('wizard.project_name_helper') }}</span>
                    @error('state.name')<span class="nx-field__error">{{ $message }}</span>@enderror
                </label>

                <div class="nx-field">
                    <span class="nx-field__label">{{ __('wizard.workspace_client') }}</span>
                    <select wire:model.live.debounce.600ms="state.workspace_id" class="nx-field__input"
                            @disabled($this->creatingWorkspace) data-wizard-workspace-select>
                        <option value="">—</option>
                        @foreach ($workspaces as $id => $name)
                            <option value="{{ $id }}" @selected((string) $this->state['workspace_id'] === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <span class="nx-field__hint">{{ __('wizard.workspace_client_helper') }}</span>
                    @error('state.workspace_id')<span class="nx-field__error">{{ $message }}</span>@enderror

                    {{-- §E2 — inline workspace creation: no dead end. --}}
                    <div class="nxw-inline-create">
                        @if (! $this->creatingWorkspace)
                            @if ($this->canCreateWorkspace())
                                <button type="button" wire:click="openInlineWorkspaceCreate" class="nx-link"
                                        data-wizard-create-workspace>
                                    + {{ __('wizard.create_workspace_inline') }}
                                </button>
                            @else
                                <span class="nx-field__hint">{{ __('wizard.workspace_needs_admin') }}</span>
                            @endif
                        @endif

                        @if ($this->creatingWorkspace)
                            <div class="nx-card nx-card--nested" data-wizard-workspace-create>
                                <div class="nx-form-grid">
                                    <label class="nx-field">
                                        <span class="nx-field__label">{{ __('workspaces.workspace_name') }}</span>
                                        <input type="text" wire:model="newWorkspaceName" class="nx-field__input"
                                               wire:keydown.enter="createWorkspaceInline" maxlength="120" />
                                        @error('newWorkspaceName')<span class="nx-field__error">{{ $message }}</span>@enderror
                                    </label>
                                    <label class="nx-field">
                                        <span class="nx-field__label">{{ __('workspaces.type') }}</span>
                                        <select wire:model="newWorkspaceKind" class="nx-field__input">
                                            <option value="client">{{ __('workspaces.type_client') }}</option>
                                            <option value="company">{{ __('workspaces.type_company') }}</option>
                                            <option value="team">{{ __('workspaces.type_team') }}</option>
                                            <option value="internal">{{ __('workspaces.type_internal') }}</option>
                                        </select>
                                    </label>
                                </div>
                                <div class="nx-card__actions">
                                    <button type="button" wire:click="createWorkspaceInline"
                                            class="nx-btn nx-btn--primary" data-wizard-workspace-save>
                                        {{ __('wizard.create_workspace_inline') }}
                                    </button>
                                    <button type="button" wire:click="cancelInlineWorkspaceCreate" class="nx-btn">
                                        {{ __('common.cancel') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

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

    {{-- Step 2 — Source (§E4/§E5): connector + connection, Advanced collapsed --}}
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

            {{-- §E4 — technical capability prose lives under a disclosure. --}}
            <details class="nx-details" data-wizard-connector-details>
                <summary class="nx-details__summary">{{ __('foundation.technical_details') }}</summary>
                <div class="nx-details__body">
                    <ul class="nx-prose-list">
                        @foreach ($this->sourceCards() as $card)
                            <li><strong>{{ $card['name'] }}</strong> — {{ $card['full_description'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </details>

            @if ($connector !== null)
                <h3 class="nx-card__subtitle">{{ __('wizard.connection_title', ['name' => $this->connectorName()]) }}</h3>
                <p class="nx-hint">{{ __('wizard.connection_helper') }}</p>

                @if ($groups['primary'] === [] && $groups['advanced'] === [])
                    <p class="nx-hint">{{ __('wizard.no_fields_needed') }}</p>
                @else
                    <div class="nx-form-grid" data-wizard-connection-fields>
                        @foreach ($groups['primary'] as $field)
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

                    @if ($groups['advanced'] !== [])
                        {{-- §E4 — Advanced collapsed by default. --}}
                        <details class="nx-advanced" data-wizard-advanced>
                            <summary>{{ __('common.advanced_options') }}</summary>
                            <div class="nx-form-grid">
                                @foreach ($groups['advanced'] as $field)
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
                                            <input type="password" wire:model="state.connection.{{ $field->key }}" class="nx-field__input"
                                                   autocomplete="new-password" />
                                        @else
                                            <input type="text" wire:model="state.connection.{{ $field->key }}" class="nx-field__input"
                                                   value="{{ $this->state['connection'][$field->key] ?? $field->default }}" />
                                        @endif
                                        @if ($field->help)
                                            <span class="nx-field__hint">{{ $field->help }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        </details>
                    @endif
                @endif

                <p class="nx-hint">{{ __('wizard.secrets_note') }}</p>
                @if ($created)
                    <p class="nx-hint">{{ __('wizard.resume_secret_note') }}</p>
                @endif

                <div class="nx-card__actions">
                    <button type="button" wire:click="testConnection" wire:loading.attr="disabled"
                            class="nx-btn nx-btn--primary" data-wizard-test-connection>
                        {{ __('common.test_connection') }}
                    </button>
                    <span wire:loading wire:target="testConnection" class="nx-hint">{{ __('wizard.test_running') }}</span>
                </div>

                @if ($this->testResult)
                    <div @class([
                            'nx-note' => true,
                            'nx-note--success' => ($this->testResult['ok'] ?? false),
                            'nx-note--danger' => ! ($this->testResult['ok'] ?? false),
                        ]) role="status" data-wizard-test-result data-wizard-test-kind="{{ $this->testResult['kind'] ?? '' }}">
                        @if ($this->testResult['ok'] ?? false)
                            <strong>{{ __('wizard.test_passed') }}</strong>
                            <span>{{ __('wizard.test_passed_read_only') }}</span>
                        @else
                            <strong>{{ __('wizard.test_failed') }}</strong>
                            @switch ($this->testResult['kind'] ?? 'network')
                                @case('private_network')
                                    {{-- §E5 — the private-network recovery path. --}}
                                    <span>{{ __('wizard.test_private_network_body') }}</span>
                                    @break
                                @case('auth')
                                    <span>{{ __('wizard.test_auth_body') }}</span>
                                    @break
                                @case('invalid')
                                    <span>{{ __('wizard.test_invalid_body') }}</span>
                                    @break
                                @default
                                    <span>{{ __('wizard.test_network_body') }}</span>
                            @endswitch

                            @if (($this->testResult['kind'] ?? '') === 'private_network')
                                <div class="nx-error__actions">
                                    @if ($this->canOpenSystemSettings())
                                        <a class="nx-btn" href="{{ \App\Filament\Pages\SettingsHub::getUrl() }}">
                                            {{ __('wizard.test_private_network_settings') }}
                                        </a>
                                    @endif
                                    <details class="nx-details nx-details--inline">
                                        <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                                        <div class="nx-details__body" dir="ltr">
                                            <code class="nx-tech">{{ $this->testResult['detail'] ?? '' }}</code>
                                        </div>
                                    </details>
                                </div>
                            @elseif (($this->testResult['detail'] ?? '') !== '')
                                <details class="nx-details nx-details--inline">
                                    <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                                    <div class="nx-details__body" dir="ltr">
                                        <code class="nx-tech">{{ $this->testResult['detail'] }}</code>
                                    </div>
                                </details>
                            @endif
                        @endif
                    </div>
                @endif
            @endif
        </section>
    @endif

    {{-- Step 3 — Destination (§E6) — TEMM-managed is the recommended path --}}
    @if ($this->step === 3)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.destination_title') }}</h2>

            <div class="nxw-cards nxw-cards--choices">
                <button type="button" wire:click="$set('state.destination', 'temm')"
                        class="nxw-card--choice @if (($this->state['destination'] ?? 'temm') === 'temm') is-selected @endif"
                        data-wizard-destination-temm>
                    <span class="nx-tag nx-tag--recommended">{{ __('wizard.destination_recommended') }}</span>
                    <strong>{{ __('wizard.destination_temm') }}</strong>
                    <span>{{ __('wizard.destination_temm_helper') }}</span>
                </button>
                <button type="button" wire:click="$set('state.destination', 'external')"
                        class="nxw-card--choice @if (($this->state['destination'] ?? '') === 'external') is-selected @endif"
                        data-wizard-destination-external>
                    <strong>{{ __('wizard.destination_external') }}</strong>
                    <span>{{ __('wizard.destination_external_helper') }}</span>
                </button>
            </div>

            {{-- §E6 — external target configuration is the Advanced path. --}}
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

    {{-- Step 4 — Analyze (§E7/§E8/§E9): a real operation with real stages --}}
    @if ($this->step === 4)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.analyze_title', ['name' => $this->state['name']]) }}</h2>
            <p class="nx-hint">{{ __('wizard.analyze_helper') }}</p>

            @if ($progress['running'])
                {{-- Live operation: real stages from real telemetry. --}}
                <ul class="nxw-stages" data-wizard-analysis-progress wire:poll.2s="tickAnalysis">
                    @foreach ($progress['stages'] as $stage)
                        <li @class([
                            'nxw-stages__item',
                            'is-active' => $stage['state'] === 'active',
                            'is-done' => $stage['state'] === 'done',
                            'is-failed' => $stage['state'] === 'failed',
                        ])>
                            <span class="nxw-stages__marker" aria-hidden="true">
                                @if ($stage['state'] === 'done')✓@elseif ($stage['state'] === 'failed')✕@else•@endif
                            </span>
                            <span class="nxw-stages__label">{{ __('wizard.stage_'.$stage['key']) }}</span>
                            @if ($stage['state'] === 'active')
                                <span class="nx-hint">{{ __('wizard.stage_running') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="nx-card__actions">
                    <button type="button" wire:click="cancelAnalysis" class="nx-btn" data-wizard-analyze-cancel>
                        {{ __('wizard.analyze_cancel') }}
                    </button>
                    <span class="nx-hint">{{ __('wizard.analyze_cancel_note') }}</span>
                </div>
            @elseif ($progress['status'] === 'cancelled')
                <div class="nx-note" role="status" data-wizard-analysis-cancelled>
                    <strong>{{ __('wizard.analyze_cancelled_title') }}</strong>
                    <span>{{ __('wizard.analyze_cancelled_body') }}</span>
                </div>
                <div class="nx-card__actions">
                    <button type="button" wire:click="startAnalysis" class="nx-btn nx-btn--primary" data-wizard-analyze>
                        {{ __('wizard.analyze_button') }}
                    </button>
                </div>
            @elseif ($summary !== [])
                {{-- §E9 — summary first. --}}
                <h3 class="nx-card__subtitle">{{ __('wizard.analyze_summary') }}</h3>
                <div class="nx-grid nx-grid--stats" data-wizard-analysis-summary>
                    @foreach (['tables', 'views', 'auth', 'storage', 'functions', 'triggers', 'policies', 'realtime'] as $key)
                        @if (($summary[$key] ?? 0) > 0)
                            <div class="nx-stat-card">
                                <span class="nx-stat-card__label">{{ __('wizard.analyze_counts_'.$key) }}</span>
                                <span class="nx-stat-card__value">{{ $summary[$key] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                {{-- §E8 — warnings are warnings; the analysis still succeeded. --}}
                @if ($warnings !== [])
                    <div class="nx-note nx-note--warning" role="status" data-wizard-analysis-warnings>
                        <strong>{{ trans_choice('wizard.analysis_completed_warnings', count($warnings), ['count' => count($warnings)]) }}</strong>
                        <ul class="nx-prose-list">
                            @foreach ($warnings as $warning)
                                <li><strong>{{ $warning['title'] }}</strong> — {{ $warning['detail'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="nx-hint">{{ __('wizard.analyze_done') }} {{ __('wizard.analyze_issues_none') }}</p>
                @endif

                {{-- Technical detail is one click away, never the face. --}}
                <details class="nx-details" data-wizard-analysis-technical>
                    <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                    <div class="nx-details__body" dir="ltr">
                        <code class="nx-tech">{{ $this->analysisTechnicalDetail() }}</code>
                    </div>
                </details>

                <div class="nx-card__actions">
                    <button type="button" wire:click="startAnalysis" class="nx-btn" data-wizard-analyze-rerun>
                        {{ __('wizard.analyze_rerun') }}
                    </button>
                </div>
            @else
                <div class="nx-card__actions">
                    <button type="button" wire:click="startAnalysis" wire:loading.attr="disabled" class="nx-btn nx-btn--primary"
                            data-wizard-analyze>
                        {{ __('wizard.analyze_button') }}
                    </button>
                    <span wire:loading wire:target="startAnalysis" class="nx-hint">{{ __('wizard.analyze_running') }}</span>
                </div>
            @endif
        </section>
    @endif

    {{-- Step 5 — Review (§E10): deterministic readiness + ONE action --}}
    @if ($this->step === 5)
        <section class="nx-card">
            <h2 class="nx-card__title">{{ __('wizard.review_title') }}</h2>
            <p class="nx-hint">{{ __('wizard.review_helper') }}</p>

            <dl class="nx-status-list" data-wizard-review>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_source') }}</dt>
                    <dd>{{ $this->connectorName() }}
                        @if (($this->state['connection']['database'] ?? '') !== '')
                            · <span class="nx-tech">{{ $this->state['connection']['database'] }}</span>
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_destination') }}</dt>
                    <dd>
                        @if (($this->state['destination'] ?? 'temm') === 'temm')
                            {{ __('wizard.destination_temm') }}
                        @else
                            {{ __('wizard.destination_external') }} @if (($this->state['target']['database'] ?? '') !== '')
                                · <span class="nx-tech">{{ $this->state['target']['database'] }}</span>
                            @endif
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_found') }}</dt>
                    <dd>
                        @if ($readiness['analysis_done'])
                            {{ trans_choice('wizard.review_tables_found', $readiness['tables'], ['count' => $readiness['tables']]) }}
                            @if (($summary['views'] ?? 0) > 0)
                                · {{ trans_choice('wizard.review_views_found', $summary['views'], ['count' => $summary['views']]) }}
                            @endif
                        @else
                            {{ __('wizard.review_not_analyzed') }}
                        @endif
                    </dd>
                </div>
                <div class="nx-status-list__row">
                    <dt>{{ __('wizard.review_ready_items') }}</dt>
                    <dd>
                        @if ($readiness['ready'])
                            {{ __('wizard.review_all_ready') }}
                        @elseif ($readiness['plan_items'] !== null && $readiness['plan_items'] > 0)
                            {{ trans_choice('wizard.review_plan_items', $readiness['plan_items'], ['count' => $readiness['plan_items']]) }}
                        @else
                            —
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
            </dl>

            {{-- §E8 — warnings listed where the user decides. --}}
            @if ($warnings !== [])
                <div class="nx-note nx-note--warning" role="status">
                    <strong>{{ trans_choice('wizard.analysis_completed_warnings', count($warnings), ['count' => count($warnings)]) }}</strong>
                </div>
            @endif

            {{-- §E10 — blocking items are named, not hidden. --}}
            @if ($blockers !== [])
                <ul class="nx-attention" data-wizard-review-blockers>
                    @foreach ($blockers as $blocker)
                        <li class="nx-attention__item nx-attention__item--warning">
                            <div><strong>{{ $blocker['title'] }}</strong></div>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- §E10 — the honest verdict. --}}
            <div class="nx-note {{ $readiness['ready'] ? 'nx-note--success' : 'nx-note--warning' }}" data-wizard-readiness>
                @if ($readiness['ready'])
                    <strong>{{ __('wizard.review_ready') }}</strong>
                @else
                    <strong>{{ __('wizard.review_not_ready') }}</strong>
                    <span>{{ $readiness['reason'] }}</span>
                @endif
            </div>

            <div class="nx-card__actions">
                @if ($readiness['ready'])
                    {{-- ONE primary action when the journey may proceed. --}}
                    <button type="button" wire:click="startMigration" class="nx-btn nx-btn--primary" data-wizard-start-migration>
                        {{ __('wizard.start_migration') }}
                    </button>
                    <span class="nx-hint">{{ __('wizard.start_migration_helper') }}</span>
                @else
                    {{-- §E10/§E11 — the primary action IS the recovery action. --}}
                    <button type="button" wire:click="startAnalysis" class="nx-btn nx-btn--primary" data-wizard-recovery-action>
                        {{ __('wizard.analyze_button') }}
                    </button>
                    <span class="nx-hint">{{ __('wizard.review_recovery_hint') }}</span>
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
