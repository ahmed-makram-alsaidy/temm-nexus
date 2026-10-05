{{--
    0.6.0 Phase C — HOME as a calm command center (audit §C1–§C15).

    Priority order: platform state → needs attention → continue where you
    left off → projects → recent activity → quiet system status.

    What is deliberately GONE from 0.5:
      - the permanent five-card KPI grid (zeros consumed the fold, §C5)
      - the two always-on backup cards (backups surface only when a backup
        actually needs attention — through the project warnings, §C8)
      - any raw verb, enum or internal id (dictionary + humanizer, §A5/§A6)

    One primary action per Home (§C1). Every section's data fails alone
    (§ERRORS): the page class wraps each source in safeSection().
--}}
<x-filament-panels::page>
    @php
        $attention = $this->attention();
        $continue = $this->continueTarget();
        $projects = $this->projectSummaries() ?? collect();
        $activity = $this->recentActivity() ?? collect();
        $system = $this->systemStatus();
        $hasProjects = $this->pulse()->hasAnyProject();
    @endphp

    {{-- 1. Platform state: greeting + one sentence + THE one primary action. --}}
    <header class="nx-hero" data-nx-inspect="home.hero" data-nx-inspect-label="Home header">
        <div class="nx-hero__text">
            <h1 class="nx-hero__greeting">{{ $this->greeting() }}, {{ $this->userName() }}.</h1>
            <p class="nx-hero__state">{{ $this->stateSentence() }}</p>
        </div>

        <div class="nx-hero__actions">
            @php $primary = $this->primaryAction(); @endphp
            <x-filament::button
                tag="a"
                href="{{ $primary['url'] }}"
                icon="{{ $hasProjects ? 'heroicon-o-arrow-right' : 'heroicon-o-plus' }}"
            >
                {{ $primary['label'] }}
            </x-filament::button>

            {{-- Secondary stays visually secondary (§C1). --}}
            @if ($hasProjects && $this->canViewWorkspaces())
                <x-filament::button
                    tag="a"
                    color="gray"
                    href="{{ \App\Filament\Pages\Workspaces::getUrl() }}"
                    icon="heroicon-o-building-office-2"
                >
                    {{ __('home.clients_and_workspaces') }}
                </x-filament::button>
            @endif
        </div>
    </header>

    @if (! $hasProjects)
        {{-- 2b. First-run Home (§C9): headline, one sentence, ONE action. --}}
        <x-nx.empty-state
            icon="heroicon-o-square-3-stack-3d"
            :title="__('home.empty_title')"
            :body="__('home.empty_body')"
            :actionUrl="\App\Filament\Pages\NewProjectWizard::getUrl()"
            :actionLabel="__('home.cta_connect_first')"
            :secondaryUrl="\App\Filament\Pages\OnboardingWizard::getUrl()"
            :secondaryLabel="__('home.learn_how')"
        />
    @else
        {{-- 2. Needs attention — the highest-priority content when non-empty. --}}
        <section class="nx-section" data-nx-inspect="home.attention" data-nx-inspect-label="Needs attention">
            @if ($attention['items']->isEmpty())
                {{-- All clear is a quiet line, not a card (§C2). --}}
                <div class="nx-allclear">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                    <span>{{ __('home.all_clear_line') }}</span>
                </div>
            @else
                <ul class="nx-attention">
                    @foreach ($attention['items'] as $row)
                        <li @class([
                            'nx-attention__item',
                            'nx-attention__item--danger' => $row['state'] === \App\Services\Product\JourneyState::BLOCKED,
                            'nx-attention__item--warning' => $row['state'] === \App\Services\Product\JourneyState::NEEDS_ATTENTION,
                        ])>
                            <x-filament::icon :icon="$row['state']->icon()" class="h-4 w-4" />
                            <div>
                                <strong>{{ $row['reason'] }}</strong>
                                {{-- The SPECIFIC reason — never the state word twice (§C2). --}}
                                <p>{{ $row['detail'] !== '' ? $row['detail'] : ($row['project']?->name ?? '') }}</p>
                                @if ($row['project'])
                                    <span class="nx-attention__project">{{ $row['project']->name }}</span>
                                @endif
                            </div>
                            @if ($row['url'])
                                <a class="nx-link" href="{{ $row['url'] }}">{{ __('home.cta_review_issue') }} →</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($attention['total'] > count($attention['items']))
                    <a class="nx-link nx-section__more" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('index') }}">
                        {{ trans_choice('home.attention_view_all', $attention['total'], ['count' => $attention['total']]) }} →
                    </a>
                @endif
            @endif
        </section>

        {{-- 3. Continue where you left off — deterministic, hidden when nothing to resume. --}}
        @if ($continue !== null && $continue['url'])
            <section class="nx-section" data-nx-inspect="home.continue" data-nx-inspect-label="Continue where you left off">
                <div class="nx-continue">
                    <span class="nx-continue__icon" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-play" class="h-5 w-5" />
                    </span>
                    <div class="nx-continue__text">
                        <strong>{{ $continue['project']->name }}</strong>
                        <p>
                            {{ __('home.continue_context', [
                                'stage' => $continue['stage']->label(),
                                'when' => $continue['at']?->diffForHumans() ?? __('home.continue_recently'),
                            ]) }}
                        </p>
                    </div>
                    <a class="nx-btn" href="{{ $continue['url'] }}">{{ __('home.cta_continue') }}</a>
                </div>
            </section>
        @endif

        {{-- 4. Projects — a compact summary, not the Projects page (§C4). --}}
        @if ($projects->isNotEmpty())
            <section class="nx-section" data-nx-inspect="home.recent_projects" data-nx-inspect-label="Projects">
                <h2 class="nx-section__title">{{ __('home.projects_title') }}</h2>
                <ul class="nx-list">
                    @foreach ($projects as $row)
                        <li>
                            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $row['project']]) }}">
                                <span class="nx-list__name">{{ $row['project']->name }}</span>
                                @if ($row['workspace'])
                                    <span class="nx-list__hint">{{ $row['workspace'] }}</span>
                                @endif
                                <span class="nx-tag">{{ strtoupper($row['project']->environment ?? 'local') }}</span>
                                <span class="nx-list__hint">{{ $row['stage']->label() }}</span>
                                <span @class(['nx-status', $this->statusClass($row['state'])])>
                                    <x-filament::icon :icon="$row['state']->icon()" class="h-3.5 w-3.5" />
                                    {{ $row['state']->label() }}
                                </span>
                                <span class="nx-list__hint">{{ $row['progress'] }}%</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <a class="nx-link nx-section__more" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('index') }}">
                    {{ __('home.projects_view_all') }} →
                </a>
            </section>
        @endif

        {{-- 5. Recent activity — human sentences, context, relative time (§C6). --}}
        @if ($this->canViewActivity())
            <section class="nx-section">
                <h2 class="nx-section__title">{{ __('home.recent_activity') }}</h2>
                @if ($activity->isEmpty())
                    <div class="nx-empty nx-empty--inline">
                        <p class="nx-empty__body">{{ __('home.activity_empty') }}</p>
                    </div>
                @else
                    <ul class="nx-timeline">
                        @foreach ($activity as $entry)
                            <li class="nx-timeline__row">
                                <span class="nx-timeline__time" title="{{ $entry['at']?->format('M j, H:i') ?? '' }}">
                                    {{ $entry['at']?->diffForHumans() ?? '—' }}
                                </span>
                                <span class="nx-timeline__what">
                                    {{ \App\Support\ActivityHumanizer::humanize($entry['action']) }}@if ($entry['project']) · {{ $entry['project'] }}@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <a class="nx-link nx-section__more" href="{{ \App\Filament\Resources\AuditLogResource::getUrl() }}">
                        {{ __('home.activity_view_all') }} →
                    </a>
                @endif
            </section>
        @endif

        {{-- 6. System status — one quiet line, only when there is something true
                to say (§C7/§C15). Degraded systems are promoted into attention. --}}
        @if ($system['state'] === 'normal')
            <p class="nx-systemline">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-3.5 w-3.5" />
                {{ __('home.system_normal') }}
                <a class="nx-link" href="{{ $this->systemUrl() }}">{{ __('home.system_details') }}</a>
            </p>
        @elseif ($system['state'] === 'degraded')
            <p class="nx-systemline nx-systemline--degraded">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-3.5 w-3.5" />
                {{ __('home.system_degraded') }}
                <a class="nx-link" href="{{ $this->systemUrl() }}">{{ __('home.system_details') }}</a>
            </p>
        @endif
    @endif
</x-filament-panels::page>
