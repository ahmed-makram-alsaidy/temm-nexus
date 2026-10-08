{{--
    0.6.0 Phase E (§E12–§E21) — the project MIGRATION JOURNEY.

    ONE journey, SIX stage tabs: Connect → Analyze → Plan → Sync → Verify →
    Cutover. Every stage answers, in its first viewport: where am I, is this
    stage okay, what is blocking me, what should I do next (the 5-second test).

    §E22 the stage states are ProjectPulse output — the canonical journey
        model (docs/STATUS_MODEL.md). This view never re-derives state.
    §E23 errors follow the accepted pattern; raw SQLSTATE/slug/env-var text
        only behind Technical details.
    §E24 empty states use x-nx.empty-state with a teaching line + action.
    §E28 bounded reads; history lives in a paginated-bounded disclosure.
--}}
@php
    $url = fn (string $stage) => \App\Filament\Resources\Projects\ProjectResource::getUrl('migration', ['record' => $project, 'stage' => $stage]);
    $canManage = $canManage;
@endphp

<div class="nx-stack">

    @if ($this->error)
        {{-- §E23 — what failed → what it means → Technical details. --}}
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

    {{-- §E12 — the journey's stage navigation. State icons come from the
         canonical JourneyState vocabulary. --}}
    <nav class="nx-journey-tabs" aria-label="{{ __('migration.title') }}" data-nx-inspect="migration.stages"
         data-nx-inspect-label="Migration stage navigation">
        @foreach ($tabs as $tab)
            @php
                $tabClasses = 'nx-journey-tab nx-journey-tab--'.$tab['state']->value
                    .($tab['key'] === $stage ? ' is-active' : '');
            @endphp
            <a href="{{ $url($tab['key']) }}"
               class="{{ $tabClasses }}">
                <x-filament::icon :icon="$tab['state']->icon()" class="h-4 w-4" />
                <span>{{ $tab['label'] }}</span>
                <span class="nx-visually-hidden">{{ $tab['state']->label() }}</span>
            </a>
        @endforeach
    </nav>

    {{-- ══════════════════ CONNECT (§E14) ══════════════════ --}}
    @if ($stage === 'connect')
        <section class="nx-section" data-nx-inspect="migration.connect" data-nx-inspect-label="Connect stage">
            <h2 class="nx-section__title">{{ __('migration.connect_source_heading') }}</h2>

            @if ($sources === [])
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    :title="__('migration.connect_empty_title')"
                    :body="__('migration.connect_empty_body')"
                    :actionUrl="$canManage ? null : null"
                />
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="openSourceForm" class="nx-btn nx-btn--primary" data-migration-add-source>
                            {{ __('migration.connect_add_source') }}
                        </button>
                    </div>
                @endif
            @else
                <ul class="nx-status-list">
                    @foreach ($sources as $source)
                        <li class="nx-status-list__row" data-migration-source>
                            <div>
                                <strong>{{ $source['name'] }}</strong>
                                <span class="nx-fact__detail">{{ __('migration.connect_source_connector') }}: {{ $source['connector'] }}</span>
                                @if ($source['status'] === 'problem' && $source['last_error'] !== '')
                                    <p class="nx-field__error">{{ __('migration.connect_last_error') }}</p>
                                @endif
                            </div>
                            <div class="nx-fact__detail">
                                <span class="nx-fact__label">{{ __('migration.connect_last_test') }}</span>
                                <span>{{ $source['last_tested_at'] ? $source['last_tested_at']->diffForHumans() : __('migration.connect_last_test_none') }}</span>
                            </div>
                            <span class="nx-status {{ $source['status'] === 'connected' ? 'nx-status--success' : ($source['status'] === 'problem' ? 'nx-status--warning' : '') }}">
                                {{ $source['status'] === 'connected' ? __('projects.source_connected') : ($source['status'] === 'problem' ? __('projects.source_problem') : __('projects.source_not_connected')) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="openSourceForm" class="nx-btn" data-migration-add-source>
                            {{ __('migration.connect_new_source') }}
                        </button>
                    </div>
                @endif
            @endif

            {{-- Inline create-source: the same no-dead-end rule as the wizard. --}}
            @if ($canManage && $creatingSource)
                <div class="nx-card nx-card--nested" data-migration-source-form>
                    <div class="nx-form-grid">
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_name') }}</span>
                            <input type="text" wire:model="sourceForm.display_name" class="nx-field__input" maxlength="120" />
                            <span class="nx-field__hint">{{ __('migration.connect_source_name_hint') }}</span>
                            @error('sourceForm.display_name')<span class="nx-field__error">{{ $message }}</span>@enderror
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_type') }}</span>
                            <select wire:model="sourceForm.type" class="nx-field__input">
                                @foreach ($connectorTypes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_host') }}</span>
                            <input type="text" wire:model="sourceForm.host" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_port') }}</span>
                            <input type="number" wire:model="sourceForm.port" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_database') }}</span>
                            <input type="text" wire:model="sourceForm.database" class="nx-field__input" />
                            @error('sourceForm.database')<span class="nx-field__error">{{ $message }}</span>@enderror
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_username') }}</span>
                            <input type="text" wire:model="sourceForm.username" class="nx-field__input" />
                        </label>
                        <label class="nx-field">
                            <span class="nx-field__label">{{ __('migration.connect_source_password_secret') }}</span>
                            <input type="text" wire:model="sourceForm.password_secret" class="nx-field__input" />
                            <span class="nx-field__hint">{{ __('migration.connect_source_password_secret_hint') }}</span>
                        </label>
                    </div>
                    <div class="nx-card__actions">
                        <button type="button" wire:click="createSource" class="nx-btn nx-btn--primary">{{ __('migration.connect_add_source') }}</button>
                        <button type="button" wire:click="cancelSourceForm" class="nx-btn">{{ __('migration.connect_cancel') }}</button>
                    </div>
                </div>
            @endif
        </section>

        <section class="nx-section">
            <h2 class="nx-section__title">{{ __('migration.connect_destination_heading') }}</h2>
            <p class="nx-fact__detail" data-migration-destination>{{ $destination }}</p>
        </section>

        {{-- §E14 — the primary action follows the state. --}}
        @if ($sources !== [])
            <div class="nx-card__actions" data-migration-connect-action>
                @php $connectState = collect($tabs)->firstWhere('key', 'connect')['state']; @endphp
                @if ($connectState === \App\Services\Product\JourneyState::COMPLETE)
                    <a class="nx-btn nx-btn--primary" href="{{ $url('analyze') }}">{{ __('migration.connect_continue_analyze') }}</a>
                @else
                    @if ($canManage)
                        <a class="nx-btn nx-btn--primary" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('migration-center', ['record' => $project]) }}">
                            {{ __('migration.connect_fix_connection') }}
                        </a>
                    @else
                        <span class="nx-fact__detail">{{ __('projects.source_problem') }}</span>
                    @endif
                @endif
            </div>
        @endif

        <x-nx.details>
            <p class="nx-fact__detail">{{ __('migration.connect_source_connector') }}: read-only access is enforced on every source session.</p>
        </x-nx.details>
    @endif

    {{-- ══════════════════ ANALYZE (§E15) ══════════════════ --}}
    @if ($stage === 'analyze')
        <section class="nx-section" data-nx-inspect="migration.analyze" data-nx-inspect-label="Analyze stage">
            @if ($analysis === null)
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    :title="__('migration.analyze_empty_title')"
                    :body="__('migration.analyze_empty_body')"
                />
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="startAnalysis" class="nx-btn nx-btn--primary" data-migration-analyze>
                            {{ __('migration.analyze_run') }}
                        </button>
                    </div>
                @endif
            @elseif ($analysisProgress['running'])
                {{-- §E7 — a real operation: real stages, ticked from the UI. --}}
                <h2 class="nx-card__subtitle">{{ __('migration.analyze_progress_title') }}</h2>
                <ul class="nxw-stages" data-migration-analysis-progress wire:poll.2s="tickAnalysis">
                    @foreach ($analysisProgress['stages'] as $stageEntry)
                        @php
                            $stageClasses = 'nxw-stages__item'
                                .($stageEntry['state'] === 'active' ? ' is-active' : '')
                                .($stageEntry['state'] === 'done' ? ' is-done' : '')
                                .($stageEntry['state'] === 'failed' ? ' is-failed' : '');
                        @endphp
                        <li class="{{ $stageClasses }}">
                            <span class="nxw-stages__marker" aria-hidden="true">
                                @if ($stageEntry['state'] === 'done')✓@elseif ($stageEntry['state'] === 'failed')✕@else•@endif
                            </span>
                            <span class="nxw-stages__label">{{ __('migration.analyze_stage_'.$stageEntry['key']) }}</span>
                            @if ($stageEntry['state'] === 'active')
                                <span class="nx-hint">{{ __('migration.analyze_stage_running') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="cancelAnalysis" class="nx-btn" data-migration-analyze-cancel>
                            {{ __('migration.analyze_cancel') }}
                        </button>
                        <span class="nx-hint">{{ __('migration.analyze_cancel_note') }}</span>
                    </div>
                @endif
            @elseif ($analysis->status === 'completed')
                {{-- §E9 — summary first. --}}
                <div class="nx-grid nx-grid--stats" data-migration-analysis-summary>
                    @foreach ((array) ($analysis->counts ?? []) as $kind => $n)
                        @if ($n > 0)
                            @php
                                $kindKey = 'migration.counts_'.$kind;
                                $kindLabel = __($kindKey) !== $kindKey ? __($kindKey) : ucfirst($kind);
                            @endphp
                            <div class="nx-stat-card">
                                <span class="nx-stat-card__label">{{ $kindLabel }}</span>
                                <span class="nx-stat-card__value">{{ $n }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                @if ($analysisWarnings !== [])
                    <div class="nx-note nx-note--warning" role="status" data-migration-analysis-warnings>
                        <strong>{{ trans_choice('wizard.analysis_completed_warnings', count($analysisWarnings), ['count' => count($analysisWarnings)]) }}</strong>
                        <ul class="nx-prose-list">
                            @foreach ($analysisWarnings as $warning)
                                <li><strong>{{ $warning['title'] }}</strong> — {{ $warning['detail'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($analysisBlockers !== [])
                    <h3 class="nx-card__subtitle">{{ __('migration.analyze_blockers_title') }}</h3>
                    <ul class="nx-attention" data-migration-analysis-blockers>
                        @foreach ($analysisBlockers as $kind => $n)
                            <li class="nx-attention__item nx-attention__item--danger">
                                <div><strong>{{ trans_choice('wizard.review_blocked_items', $n, ['count' => $n, 'kind' => __('wizard.analyze_counts_'.$kind)]) }}</strong></div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <x-nx.details>
                    <div dir="ltr"><code class="nx-tech">{{ $analysisTechnical }}</code></div>
                </x-nx.details>

                <div class="nx-card__actions">
                    @if ($canManage)
                        <button type="button" wire:click="startAnalysis" class="nx-btn" data-migration-analyze-rerun>
                            {{ __('migration.analyze_rerun') }}
                        </button>
                    @endif
                    <a class="nx-btn nx-btn--primary" href="{{ $url('plan') }}" data-migration-continue-plan>{{ __('migration.analyze_continue_plan') }}</a>
                </div>
            @elseif ($analysis->status === 'failed')
                {{-- §E8 — blocking failures speak plainly; detail is one click away. --}}
                <div class="nx-error" role="alert" data-migration-analysis-failed>
                    <span class="nx-error__icon" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-no-symbol" class="h-5 w-5" />
                    </span>
                    <div class="nx-error__content">
                        <p class="nx-error__title">{{ __('migration.analyze_failed_title') }}</p>
                        <p class="nx-error__body">{{ __('migration.analyze_failed_body') }}</p>
                        <div class="nx-error__actions">
                            @if ($canManage)
                                <button type="button" wire:click="startAnalysis" class="nx-btn">{{ __('migration.analyze_run') }}</button>
                            @endif
                            <details class="nx-details nx-details--inline">
                                <summary class="nx-details__summary">{{ __('foundation.error_technical_details') }}</summary>
                                <div class="nx-details__body" dir="ltr">
                                    <code class="nx-tech">{{ $analysisTechnical }}</code>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            @else
                {{-- cancelled --}}
                <div class="nx-note" role="status" data-migration-analysis-cancelled>
                    <strong>{{ __('migration.analyze_cancelled_title') }}</strong>
                </div>
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="startAnalysis" class="nx-btn nx-btn--primary">{{ __('migration.analyze_run') }}</button>
                    </div>
                @endif
            @endif

            {{-- §E21 — the Copilot as contextual assistance, when configured. --}}
            @if ($copilot['available'] && $copilot['hasAnalysis'] && $canManage)
                <div class="nx-card nx-card--nested" data-migration-copilot>
                    <h3 class="nx-card__subtitle">{{ __('migration.copilot_panel_title') }}</h3>
                    <p class="nx-hint">{{ __('migration.copilot_panel_hint') }}</p>
                    <div class="nx-card__actions">
                        <button type="button" wire:click="explainBlockers"
                                wire:confirm="{{ __('migration.copilot_explain_blockers') }}?"
                                class="nx-btn">{{ __('migration.copilot_explain_blockers') }}</button>
                        <a class="nx-link" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('copilot', ['record' => $project]) }}">
                            {{ __('migration.copilot_open') }}
                        </a>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- ══════════════════ PLAN (§E16) ══════════════════ --}}
    @if ($stage === 'plan')
        <section class="nx-section" data-nx-inspect="migration.plan" data-nx-inspect-label="Plan stage">
            @if ($plan === null)
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    :title="__('migration.plan_empty_title')"
                    :body="__('migration.plan_empty_body')"
                />
                @if ($canManage)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="generatePlan" class="nx-btn nx-btn--primary" @disabled(! $hasAnalysis) data-migration-generate-plan>
                            {{ __('migration.plan_generate') }}
                        </button>
                        @if (! $hasAnalysis)
                            <span class="nx-hint">{{ __('migration.error_no_completed_analysis') }}</span>
                        @endif
                    </div>
                @endif
            @else
                <h2 class="nx-card__subtitle">{{ __('migration.plan_heading') }}</h2>
                <p class="nx-hint" data-migration-plan-scope>{{ __('migration.plan_items_frag', ['count' => $planItems]) }}</p>

                <ul class="nx-status-list" data-migration-plan-steps>
                    @foreach ($planStages as $step)
                        <li class="nx-status-list__row">
                            <div>
                                <strong>{{ __('migration.plan_step', ['n' => $step['stage'] + 1]) }}</strong>
                                <span class="nx-fact__detail">
                                    {{ implode(', ', $step['names']) }}@if ($step['more'] > 0) +{{ $step['more'] }} @endif
                                </span>
                            </div>
                            <span class="nx-fact__detail">{{ $step['count'] }}</span>
                        </li>
                    @endforeach
                </ul>

                <h3 class="nx-card__subtitle">{{ __('migration.plan_not_moving_title') }}</h3>
                @if ($planNotMoving === [])
                    <p class="nx-hint" data-migration-plan-clean>{{ __('migration.plan_not_moving_empty') }}</p>
                @else
                    <ul class="nx-attention" data-migration-plan-not-moving>
                        @foreach ($planNotMoving as $kind => $n)
                            <li class="nx-attention__item nx-attention__item--warning">
                                <div><strong>{{ trans_choice('wizard.review_blocked_items', $n, ['count' => $n, 'kind' => __('wizard.analyze_counts_'.$kind)]) }}</strong></div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="nx-card__actions">
                    @if ($canManage)
                        <button type="button" wire:click="generatePlan" class="nx-btn" data-migration-generate-plan>
                            {{ __('migration.plan_regenerate') }}
                        </button>
                    @endif
                    <a class="nx-btn nx-btn--primary" href="{{ $url('sync') }}" data-migration-continue-sync>{{ __('migration.plan_continue_sync') }}</a>
                </div>
            @endif
        </section>
    @endif

    {{-- ══════════════════ SYNC (§E17) ══════════════════ --}}
    @if ($stage === 'sync')
        <section class="nx-section" data-nx-inspect="migration.sync" data-nx-inspect-label="Sync stage">
            @if ($latestRun === null)
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    :title="__('migration.sync_empty_title')"
                    :body="__('migration.sync_empty_body')"
                />
                @if (! $hasPlan)
                    <p class="nx-hint">{{ __('migration.sync_no_runs_hint') }}</p>
                @endif
                @if ($canManage && $hasPlan)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="openRunForm" class="nx-btn nx-btn--primary" data-migration-start-run>
                            {{ __('migration.sync_start_run') }}
                        </button>
                    </div>
                @endif
            @else
                {{-- The current run: status, progress, direction, mode, last event. --}}
                <dl class="nx-status-list" data-migration-run>
                    <div class="nx-status-list__row">
                        <dt>{{ __('labels.status') }}</dt>
                        <dd>
                            @php
                                $runStatusClass = 'nx-status'
                                    .($latestRun->status === 'completed' ? ' nx-status--success' : '')
                                    .($latestRun->status === 'running' ? ' nx-status--warning' : '')
                                    .($latestRun->status === 'failed' ? ' nx-status--danger' : '');
                                // Stored enum → status dictionary word; the
                                // raw value stays in audit/technical views.
                                $runStatusLabel = \App\Support\ProductStatus::label((string) $latestRun->status);
                            @endphp
                            <span class="{{ $runStatusClass }}">{{ $runStatusLabel }}</span>
                        </dd>
                    </div>
                    <div class="nx-status-list__row">
                        <dt>{{ __('migration.sync_progress') }}</dt>
                        <dd class="nx-num">{{ $latestRunProgress['done'] ?? 0 }} / {{ $latestRunProgress['total'] ?? 0 }}</dd>
                    </div>
                    <div class="nx-status-list__row">
                        <dt>{{ __('migration.sync_target') }}</dt>
                        <dd><bdi dir="ltr">{{ __('migration.sync_source_to_target', ['source' => $sourceName ?? '—', 'target' => $latestRunTarget ?? '—']) }}</bdi></dd>
                    </div>
                    <div class="nx-status-list__row">
                        <dt>{{ __('migration.sync_mode') }}</dt>
                        <dd>{{ $latestRun->dry_run ? __('migration.sync_mode_dry_run') : __('migration.sync_mode_'.(string) $latestRun->mode) }}</dd>
                    </div>
                    @if ($latestRunLastItem)
                        <div class="nx-status-list__row">
                            <dt>{{ __('migration.sync_last_activity') }}</dt>
                            <dd>{{ $latestRunLastItem }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($syncDetail)
                    <div class="nx-fact" data-migration-livesync>
                        <span class="nx-fact__label">{{ __('journey.stage_sync') }}</span>
                        <span class="nx-fact__value">{{ $syncDetail['label'] }}</span>
                        <span class="nx-fact__detail">{{ $syncDetail['detail'] }}</span>
                    </div>
                @endif

                @if ($canManage && ! $startingRun)
                    <div class="nx-card__actions">
                        <button type="button" wire:click="openRunForm" class="nx-btn" data-migration-start-run>
                            {{ __('migration.sync_start_run') }}
                        </button>
                    </div>
                @endif
            @endif

            @if ($canManage)
                <p class="nx-hint" data-migration-guard-plain>{{ __('migration.sync_guard_plain') }}</p>
            @endif

            {{-- Inline run form: dry run default; external target = Advanced. --}}
            @if ($canManage && $startingRun)
                <div class="nx-card nx-card--nested" data-migration-run-form>
                    <label class="nx-field">
                        <span class="nx-field__label">{{ __('migration.sync_mode') }}</span>
                        <select wire:model.live="runMode" class="nx-field__input" data-migration-run-mode>
                            <option value="dry_run">{{ __('migration.sync_mode_dry_run') }}</option>
                            <option value="rehearsal">{{ __('migration.sync_mode_rehearsal') }}</option>
                            {{-- 0.6.1 — the managed destination's real-transfer
                                 path. Hidden — not merely refused — where the
                                 platform's production guard would reject the
                                 run anyway; the enforcing guard stays in
                                 MigrationRunManager (GUARD 1). --}}
                            @unless ($productionTarget)
                                <option value="real">{{ __('migration.sync_mode_real') }}</option>
                            @endunless
                            <option value="external_target">{{ __('wizard.destination_external') }}</option>
                        </select>
                        <span class="nx-field__hint">
                            @if ($runMode === 'real')
                                {{ __('migration.sync_start_real_hint') }}
                            @else
                                {{ __('migration.sync_start_dry_run') }}
                            @endif
                        </span>
                    </label>

                    @if ($runMode === 'external_target')
                        <details class="nx-advanced" open>
                            <summary>{{ __('common.advanced_options') }}</summary>
                            <div class="nx-form-grid">
                                <label class="nx-field">
                                    <span class="nx-field__label">{{ __('wizard.target_host') }}</span>
                                    <input type="text" wire:model="targetForm.host" class="nx-field__input" />
                                    @error('targetForm.host')<span class="nx-field__error">{{ $message }}</span>@enderror
                                </label>
                                <label class="nx-field">
                                    <span class="nx-field__label">{{ __('wizard.target_port') }}</span>
                                    <input type="number" wire:model="targetForm.port" class="nx-field__input" />
                                </label>
                                <label class="nx-field">
                                    <span class="nx-field__label">{{ __('wizard.target_database') }}</span>
                                    <input type="text" wire:model="targetForm.database" class="nx-field__input" />
                                    @error('targetForm.database')<span class="nx-field__error">{{ $message }}</span>@enderror
                                </label>
                                <label class="nx-field">
                                    <span class="nx-field__label">{{ __('wizard.target_username') }}</span>
                                    <input type="text" wire:model="targetForm.username" class="nx-field__input" />
                                </label>
                                <label class="nx-field">
                                    <span class="nx-field__label">{{ __('migration.connect_source_password_secret') }}</span>
                                    <input type="text" wire:model="targetForm.password_secret" class="nx-field__input" />
                                    <span class="nx-field__hint">{{ __('migration.connect_source_password_secret_hint') }}</span>
                                </label>
                                <label class="nx-check">
                                    <input type="checkbox" wire:model="targetDisposable" />
                                    <span>{{ __('wizard.target_disposable') }}</span>
                                </label>
                            </div>
                        </details>
                    @endif

                    <div class="nx-card__actions">
                        <button type="button" wire:click="startRun" class="nx-btn nx-btn--primary" data-migration-run-submit>
                            {{ __('migration.sync_start_run') }}
                        </button>
                        <button type="button" wire:click="cancelRunForm" class="nx-btn">{{ __('migration.connect_cancel') }}</button>
                    </div>
                </div>
            @endif

            {{-- §E28 — run history is bounded and lives behind a disclosure. --}}
            @if (count($runHistory) > 0)
                <x-nx.details>
                    <div class="cp-tablewrap">
                        <table class="cp-grid" data-migration-run-history>
                            <thead>
                                <tr>
                                    <th>{{ __('migration.sync_mode') }}</th>
                                    <th>{{ __('labels.status') }}</th>
                                    <th>{{ __('migration.sync_progress') }}</th>
                                    <th>{{ __('migration.sync_target') }}</th>
                                    <th>{{ __('labels.th_finished') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($runHistory as $run)
                                    <tr>
                                        <td>{{ $run['mode'] }}</td>
                                        <td>{{ $run['status'] }}</td>
                                        <td>{{ $run['progress']['done'] ?? 0 }}/{{ $run['progress']['total'] ?? 0 }}</td>
                                        <td><span class="nx-tech">{{ $run['target'] }}</span></td>
                                        <td>{{ $run['finished_at']?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-nx.details>
            @endif
        </section>
    @endif

    {{-- ══════════════════ VERIFY (§E18) ══════════════════ --}}
    @if ($stage === 'verify')
        <section class="nx-section" data-nx-inspect="migration.verify" data-nx-inspect-label="Verify stage">
            @if (($counts['green'] ?? 0) + ($counts['yellow'] ?? 0) + ($counts['red'] ?? 0) === 0 && $groups['not_applicable'] === [])
                <x-nx.empty-state
                    icon="heroicon-o-inbox"
                    :title="__('migration.verify_empty_title')"
                    :body="__('migration.verify_empty_body')"
                />
            @else
                <div class="nx-grid nx-grid--stats" data-migration-verify-groups>
                    <div class="nx-stat-card">
                        <span class="nx-stat-card__label">{{ __('migration.verify_passed') }}</span>
                        <span class="nx-stat-card__value nx-stat-card__value--success nx-num">{{ $counts['green'] ?? 0 }}</span>
                    </div>
                    <div class="nx-stat-card">
                        <span class="nx-stat-card__label">{{ __('migration.verify_needs_review') }}</span>
                        <span class="nx-stat-card__value nx-stat-card__value--warning nx-num">{{ $counts['yellow'] ?? 0 }}</span>
                    </div>
                    <div class="nx-stat-card">
                        <span class="nx-stat-card__label">{{ __('migration.verify_blocked') }}</span>
                        <span class="nx-stat-card__value nx-stat-card__value--danger nx-num">{{ $counts['red'] ?? 0 }}</span>
                    </div>
                </div>

                {{-- §E18 — exact recovery context: each group names its checks. --}}
                @foreach (['blocked' => 'verify_blocked', 'needs_review' => 'verify_needs_review', 'passed' => 'verify_passed'] as $groupKey => $titleKey)
                    @if (($groups[$groupKey] ?? []) !== [])
                        <h3 class="nx-card__subtitle">{{ __($titleKey) }}</h3>
                        <ul class="nx-status-list">
                            @foreach ($groups[$groupKey] as $check)
                                <li class="nx-status-list__row" data-migration-verify-check>
                                    <div>
                                        <strong>{{ $check['title'] }}</strong>
                                        <span class="nx-fact__detail">{{ $check['detail'] }}</span>
                                    </div>
                                    @if ($check['blocks_production'])
                                        <span class="nx-status nx-status--danger">{{ __('migration.verify_check_blocks') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            @endif

            <div class="nx-card__actions">
                <button type="button" wire:click="evaluateReadiness" class="nx-btn" data-migration-verify-evaluate>
                    {{ __('migration.verify_evaluate') }}
                </button>
            </div>
        </section>
    @endif

    {{-- ══════════════════ CUTOVER (§E19) ══════════════════ --}}
    @if ($stage === 'cutover')
        <p class="nx-hint" data-migration-cutover-note>{{ __('migration.cutover_stage_note') }}</p>
        {{-- The accepted gate model, rendered by the SAME view and service as
             the standalone Cutover page — the two cannot disagree. --}}
        <div data-migration-cutover>{!! $cutover !!}</div>
    @endif
</div>
