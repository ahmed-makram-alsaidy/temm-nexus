{{--
    0.4.0 Phase D (§9) — PROJECT OVERVIEW; 0.6.0 Phase D (§D2–§D7) — refined
    into the canonical project command center.

    Composition, in order:
      identity  ->  one primary CTA  ->  progress / stage / source / health /
      Live Sync / last backup / readiness  ->  the migration journey  ->
      attention  ->  activity  ->  Technical details (all telemetry and every
      internal identifier, preserved behind the disclosure).

    0.6.0 Phase D changes: every visible string is translated (§D10); the
    subheading and facts never leak technical identifiers (§D7); the source
    connection is labeled as its own concept instead of flattening into one
    "health" value (§D9); the readiness fact says "not run yet" instead of a
    fake zero (§D3); the activity feed is humanized with relative times
    (§D6); nothing was removed that was already here.
--}}
@php
    $overall = $overall;
    $progress = $progress;
    $current = $current;
    $primary = $primaryAction;
    $blockers = $blockers;
    $warnings = $warnings;
    // Phase I — this user's appearance preferences for the overview.
    $ui = $ui;

    // §D9 — the source connection is a distinct concept from project-database
    // health. It gets its own label, never flattened into one "Health" value.
    $connectState = $journey[0]['state'] ?? null;
    $sourceLabel = match (true) {
        $connectState === \App\Services\Product\JourneyState::COMPLETE => __('projects.source_connected'),
        $connectState === \App\Services\Product\JourneyState::NEEDS_ATTENTION,
        $connectState === \App\Services\Product\JourneyState::BLOCKED => __('projects.source_problem'),
        default => __('projects.source_not_connected'),
    };
    $sourceTone = match (true) {
        $connectState === \App\Services\Product\JourneyState::COMPLETE => 'success',
        $connectState === \App\Services\Product\JourneyState::NEEDS_ATTENTION,
        $connectState === \App\Services\Product\JourneyState::BLOCKED => 'warning',
        default => 'neutral',
    };
@endphp

<div class="nx-stack">
    {{-- Identity + the single primary action. --}}
    <header class="nx-hero">
        <div class="nx-hero__text">
            <span @class(['nx-status', 'nx-status--'.$overall->tone()])>
                <x-filament::icon :icon="$overall->icon()" class="h-3.5 w-3.5" />
                {{ $overall->label() }}
            </span>
            <p class="nx-hero__state">{{ $primary['description'] }}</p>
        </div>
        <div class="nx-hero__actions">
            <x-filament::button tag="a" href="{{ $primary['url'] }}" icon="heroicon-o-arrow-right">
                {{ $primary['label'] }}
            </x-filament::button>
        </div>
    </header>

    {{-- The facts a user needs in five seconds. --}}
    <div
        class="nx-overview-head @if (($ui['project.overview.progress']['position'] ?? null) === 'first') nx-order-progress-first @elseif (($ui['project.overview.progress']['position'] ?? null) === 'last') nx-order-progress-last @endif"
    >
        @if ($ui['project.overview.progress']['visibility'] ?? true)
            <div
                class="nx-overview-head__progress"
                data-nx-inspect="project.overview.progress"
                data-nx-inspect-label="Migration progress card"
            >
                <span class="nx-fact__label">{{ __('projects.fact_progress') }}</span>
                <span class="nx-overview-head__pct">{{ $progress }}%</span>
                <div class="nx-bar" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                     aria-label="{{ __('projects.fact_progress') }}">
                    <div class="nx-bar__fill" style="width: {{ $progress }}%"></div>
                </div>
                <span class="nx-fact__detail">{{ __('projects.fact_stage') }}: {{ $current->label() }}</span>
            </div>
        @endif

        @if ($ui['project.overview.facts']['visibility'] ?? true)
            <div class="nx-overview-head__facts" data-nx-inspect="project.overview.facts" data-nx-inspect-label="Readiness facts">
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.fact_health') }}</span>
                    <span class="nx-fact__value">{{ $project->healthLabel() }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.fact_source') }}</span>
                    <span @class(['nx-fact__value', 'nx-status', 'nx-status--'.$sourceTone])>{{ $sourceLabel }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.fact_sync') }}</span>
                    <span class="nx-fact__value">{{ $sync['label'] }}</span>
                    <span class="nx-fact__detail">{{ $sync['detail'] }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.fact_backup') }}</span>
                    <span class="nx-fact__value">{{ $backup['label'] }}</span>
                    <span class="nx-fact__detail">{{ $backup['detail'] }}</span>
                </div>
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.fact_readiness') }}</span>
                    <span class="nx-fact__value">
                        @if ($readiness['failed'] > 0)
                            {{ trans_choice('projects.readiness_blocking', $readiness['failed'], ['count' => $readiness['failed']]) }}
                        @elseif ($readiness['warned'] > 0)
                            {{ trans_choice('projects.readiness_review', $readiness['warned'], ['count' => $readiness['warned']]) }}
                        @elseif ($readiness['total'] > 0)
                            {{ __('projects.readiness_all_pass', ['count' => $readiness['total']]) }}
                        @else
                            {{ __('projects.readiness_not_run') }}
                        @endif
                    </span>
                </div>
            </div>
        @endif
    </div>

    {{-- The journey: where am I, what is done, what is blocked, what is next. --}}
    <section
        class="nx-section @if (($ui['project.overview.journey']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['project.overview.journey']['density'] }} @endif"
        data-nx-inspect="project.overview.journey"
        data-nx-inspect-label="Migration journey"
    >
        <h2 class="nx-section__title">{{ __('projects.journey_title') }}</h2>
        <x-nx.journey :journey="$journey" :current="$current" />
    </section>

    {{-- Why, not just that. §10 — same language and semantics as Home (§D5). --}}
    @if (($blockers !== [] || $warnings !== []) && ($ui['project.overview.attention']['visibility'] ?? true))
        <section class="nx-section" data-nx-inspect="project.overview.attention" data-nx-inspect-label="Project attention list">
            <h2 class="nx-section__title">{{ __('projects.attention_title') }}</h2>
            <ul class="nx-attention">
                @foreach (array_merge($blockers, $warnings) as $item)
                    <li @class([
                        'nx-attention__item',
                        'nx-attention__item--danger' => $item['severity'] === 'danger',
                        'nx-attention__item--warning' => $item['severity'] === 'warning',
                    ])>
                        <x-filament::icon
                            icon="{{ $item['severity'] === 'danger' ? 'heroicon-o-no-symbol' : 'heroicon-o-exclamation-triangle' }}"
                            class="h-4 w-4" />
                        <div>
                            <strong>{{ $item['title'] }}</strong>
                            <p>{{ $item['detail'] }}</p>
                        </div>
                        @if ($item['url'])
                            <a class="nx-link" href="{{ $item['url'] }}">{{ __('projects.attention_open') }} →</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Recent activity: human sentences + relative time (§D6). --}}
    @if ($ui['project.overview.activity']['visibility'] ?? true)
        <section class="nx-section" data-nx-inspect="project.overview.activity" data-nx-inspect-label="Recent activity">
            <h2 class="nx-section__title">{{ __('projects.activity_title') }}</h2>
            @php $activity = $legacy['activity'] ?? []; @endphp
            @if ($activity === [])
                <div class="nx-empty nx-empty--inline">
                    <p class="nx-empty__body">{{ __('projects.activity_empty') }}</p>
                </div>
            @else
                <ul class="nx-timeline">
                    @foreach (array_slice($activity, 0, 5) as $a)
                        <li class="nx-timeline__row">
                            <span class="nx-timeline__time" title="{{ $a['time'] ?? '' }}">
                                @if (! empty($a['at']))
                                    {{ \Carbon\Carbon::parse($a['at'])->diffForHumans() }}
                                @else
                                    —
                                @endif
                            </span>
                            <span class="nx-timeline__what">{{ $a['text'] ?? '' }}</span>
                        </li>
                    @endforeach
                </ul>
                <a class="nx-link nx-section__more" href="{{ \App\Filament\Resources\AuditLogResource::getUrl() }}">
                    {{ __('projects.activity_view') }} →
                </a>
            @endif
        </section>
    @endif

    {{-- Progressive disclosure: everything technical, nothing removed (§D7). --}}
    <details class="nx-advanced" @if ($ui['project.overview.advanced']['expanded_by_default'] ?? false) open @endif
             data-nx-inspect="project.overview.advanced" data-nx-inspect-label="Technical details">
        <summary>{{ __('projects.technical_title') }}</summary>
        <div class="nx-advanced__body">
            {{-- §D7: the project's own internal identifiers live here — and
                 nowhere else on the page. Values render LTR-isolated. --}}
            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_project_id') }}</span>
                <span class="nx-fact__value nx-num">{{ $project->getKey() }}</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_slug') }}</span>
                <x-nx.tech>{{ $project->slug }}</x-nx.tech>
            </div>

            @if ($project->workspace)
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.tech_workspace') }}</span>
                    <span class="nx-fact__value">{{ $project->workspace->name }}</span>
                </div>
            @endif

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_environment') }}</span>
                <span class="nx-fact__value">{{ $project->environment ?? 'local' }}</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_db_name') }}</span>
                <x-nx.tech>{{ $project->db_name ?: '—' }}</x-nx.tech>
            </div>

            @if (filled($project->redis_prefix))
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.tech_redis_prefix') }}</span>
                    <x-nx.tech>{{ $project->redis_prefix }}</x-nx.tech>
                </div>
            @endif

            @if (filled($project->api_domain))
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.tech_api_domain') }}</span>
                    <x-nx.tech>{{ $project->api_domain }}</x-nx.tech>
                </div>
            @endif

            @if (filled($project->domain))
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('projects.tech_domain') }}</span>
                    <x-nx.tech>{{ $project->domain }}</x-nx.tech>
                </div>
            @endif

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_deployed') }}</span>
                <span class="nx-fact__value">
                    @if ($project->last_deployed_at)
                        {{ $project->last_deployed_at->diffForHumans() }}
                    @else
                        {{ __('projects.tech_never_deployed') }}
                    @endif
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_database') }}</span>
                <span class="nx-fact__value">
                    @if (! ($legacy['available'] ?? false))
                        {{ __('projects.tech_unavailable') }}
                    @elseif ($legacy['database_reachable'] === true)
                        {{ __('projects.tech_reachable') }}
                    @elseif ($legacy['database_reachable'] === false)
                        {{ __('projects.tech_unreachable') }}
                    @else
                        {{ __('projects.tech_no_result') }}
                    @endif
                </span>
                <span class="nx-fact__detail">
                    {{ $legacy['database_connections'] !== null
                        ? trans_choice('projects.tech_connections', $legacy['database_connections'], ['count' => $legacy['database_connections']])
                        : __('projects.tech_no_connection_data') }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_api_requests') }}</span>
                <span class="nx-fact__value nx-num">{{ $legacy['pulse_requests'] ?? 0 }}</span>
                <span class="nx-fact__detail">
                    {{ trans_choice('projects.tech_pulse_note', $legacy['pulse_errors'] ?? 0, ['count' => $legacy['pulse_errors'] ?? 0]) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_queue') }}</span>
                <span class="nx-fact__value nx-num">
                    {{ __('projects.tech_queue_pending_failed', ['pending' => $legacy['queue_pending'] ?? 0, 'failed' => $legacy['queue_failed'] ?? 0]) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_db_size') }}</span>
                <span class="nx-fact__value nx-num">
                    {{ ($legacy['bytes'] ?? fn () => '—')($legacy['db_size'] ?? null) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_storage') }}</span>
                <span class="nx-fact__value nx-num">
                    {{ ($legacy['bytes'] ?? fn () => '—')($legacy['storage_bytes'] ?? null) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_app_users') }}</span>
                <span class="nx-fact__value nx-num">{{ $legacy['app_users'] ?? '—' }}</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">{{ __('projects.tech_functions') }}</span>
                <span class="nx-fact__value nx-num">{{ $legacy['functions'] ?? 0 }}</span>
            </div>

            @if ($sync['lagSeconds'] !== null)
                <div class="nx-fact">
                    <span class="nx-fact__label">{{ __('labels.live_sync_lag') }}</span>
                    <span class="nx-fact__value nx-num">{{ $sync['lagSeconds'] }}s</span>
                    <span class="nx-fact__detail">{{ __('labels.time_since_the_last_applied_change') }}</span>
                </div>
            @endif
        </div>
    </details>
</div>
