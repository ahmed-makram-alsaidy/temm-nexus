{{--
    0.4.0 Phase D (§9) — PROJECT OVERVIEW.

    The strongest screen in the product. Composition, in order:
      identity  ->  one primary CTA  ->  progress / stage / health / Live Sync /
      last backup / readiness  ->  the migration journey  ->  attention  ->
      activity  ->  Advanced details (all v0.3.0 telemetry, preserved).

    Nothing from v0.3.0 was deleted: the database/Pulse/queue/storage figures
    moved into the Advanced disclosure.
--}}
@php
    $overall = $overall;
    $progress = $progress;
    $current = $current;
    $primary = $primaryAction;
    $blockers = $blockers;
    $warnings = $warnings;
@endphp

<div class="nx-stack">
    {{-- Identity + the single primary action. --}}
    <header class="nx-hero">
        <div class="nx-hero__text">
            <span @class(['nx-status', 'nx-status--'.$overall->tone()])>
                <x-filament::icon :icon="$overall->icon()" class="h-3.5 w-3.5" />
                {{ $overall->label() }}
            </span>
            <p class="nx-hero__state">{{ $current->description() }}</p>
        </div>
        <div class="nx-hero__actions">
            <x-filament::button tag="a" href="{{ $primary['url'] }}" icon="heroicon-o-arrow-right">
                {{ $primary['label'] }}
            </x-filament::button>
        </div>
    </header>

    {{-- The facts a user needs in five seconds. --}}
    <div class="nx-overview-head">
        <div class="nx-overview-head__progress">
            <span class="nx-fact__label">Migration progress</span>
            <span class="nx-overview-head__pct">{{ $progress }}%</span>
            <div class="nx-bar" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                 aria-label="Migration progress">
                <div class="nx-bar__fill" style="width: {{ $progress }}%"></div>
            </div>
            <span class="nx-fact__detail">Current stage: {{ $current->label() }}</span>
        </div>

        <div class="nx-overview-head__facts">
            <div class="nx-fact">
                <span class="nx-fact__label">Health</span>
                <span class="nx-fact__value">{{ $project->healthLabel() }}</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Live Sync</span>
                <span class="nx-fact__value">{{ $sync['label'] }}</span>
                <span class="nx-fact__detail">{{ $sync['detail'] }}</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Last backup</span>
                <span class="nx-fact__value">{{ $backup['label'] }}</span>
                <span class="nx-fact__detail">{{ $backup['detail'] }}</span>
            </div>
            <div class="nx-fact">
                <span class="nx-fact__label">Readiness</span>
                <span class="nx-fact__value">
                    @if ($blockers !== [])
                        {{ count($blockers) }} blocking
                    @elseif ($warnings !== [])
                        {{ count($warnings) }} to review
                    @else
                        No issues
                    @endif
                </span>
            </div>
        </div>
    </div>

    {{-- The journey: where am I, what is done, what is blocked, what is next. --}}
    <section class="nx-section">
        <h2 class="nx-section__title">Migration journey</h2>
        <x-nx.journey :journey="$journey" :current="$current" />
    </section>

    {{-- Why, not just that. §10. --}}
    @if ($blockers !== [] || $warnings !== [])
        <section class="nx-section">
            <h2 class="nx-section__title">Needs attention</h2>
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
                            <a class="nx-link" href="{{ $item['url'] }}">Open →</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Recent activity. --}}
    <section class="nx-section">
        <h2 class="nx-section__title">Recent activity</h2>
        @php $activity = $legacy['activity'] ?? []; @endphp
        @if ($activity === [])
            <div class="nx-empty nx-empty--inline">
                <p class="nx-empty__body">
                    No activity recorded yet. A quiet log does not by itself mean the system is healthy.
                </p>
            </div>
        @else
            <ul class="nx-timeline">
                @foreach (array_slice($activity, 0, 8) as $a)
                    <li class="nx-timeline__row">
                        <span class="nx-timeline__time">{{ $a['time'] ?? '—' }}</span>
                        <span class="nx-timeline__what">{{ $a['text'] ?? '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Progressive disclosure: everything technical, nothing removed. --}}
    <details class="nx-advanced">
        <summary>Advanced details</summary>
        <div class="nx-advanced__body">
            <div class="nx-fact">
                <span class="nx-fact__label">Database</span>
                <span class="nx-fact__value">
                    @if (! ($legacy['available'] ?? false))
                        Unavailable
                    @elseif ($legacy['database_reachable'] === true)
                        Reachable
                    @elseif ($legacy['database_reachable'] === false)
                        Unreachable
                    @else
                        No result
                    @endif
                </span>
                <span class="nx-fact__detail">
                    {{ $legacy['database_connections'] !== null ? $legacy['database_connections'].' connection(s)' : 'no connection data' }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">API requests</span>
                <span class="nx-fact__value nx-num">{{ $legacy['pulse_requests'] ?? 0 }}</span>
                <span class="nx-fact__detail">{{ $legacy['pulse_errors'] ?? 0 }} error(s) · Pulse snapshot</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Queue</span>
                <span class="nx-fact__value nx-num">
                    {{ $legacy['queue_pending'] ?? 0 }} pending · {{ $legacy['queue_failed'] ?? 0 }} failed
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Database size</span>
                <span class="nx-fact__value nx-num">
                    {{ ($legacy['bytes'] ?? fn () => '—')($legacy['db_size'] ?? null) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Storage</span>
                <span class="nx-fact__value nx-num">
                    {{ ($legacy['bytes'] ?? fn () => '—')($legacy['storage_bytes'] ?? null) }}
                </span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Application users</span>
                <span class="nx-fact__value nx-num">{{ $legacy['app_users'] ?? '—' }}</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Runtime functions</span>
                <span class="nx-fact__value nx-num">{{ $legacy['functions'] ?? 0 }}</span>
            </div>

            <div class="nx-fact">
                <span class="nx-fact__label">Project slug</span>
                <span class="nx-fact__value nx-code">{{ $project->slug }}</span>
            </div>

            @if ($sync['lagSeconds'] !== null)
                <div class="nx-fact">
                    <span class="nx-fact__label">Live Sync lag</span>
                    <span class="nx-fact__value nx-num">{{ $sync['lagSeconds'] }}s</span>
                    <span class="nx-fact__detail">Time since the last applied change.</span>
                </div>
            @endif
        </div>
    </details>
</div>
