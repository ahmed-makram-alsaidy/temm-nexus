{{--
    0.4.0 Phase D — PLATFORM HOME (§7).

    Hierarchy, in the order the mission specifies:
      greeting + state  ->  primary CTA  ->  summary  ->  needs attention
      ->  active migrations  ->  recent projects  ->  infrastructure  ->  activity

    Infrastructure is deliberately LAST: v0.3.0 put it first.
--}}
<x-filament-panels::page>
    @php
        $pulse = $this->pulse();
        $summary = $pulse->summary();
        $attention = $pulse->projectsNeedingAttention();
        $readyForCutover = $pulse->readyForCutoverProjects();
        $backups = $pulse->backupSummary();
        $activity = $pulse->recentActivity(8);
        $projects = $pulse->projects();
        // Phase I — this user's appearance preferences (visibility, density).
        $ui = $this->uiPreferences();
    @endphp

    {{-- 1. Greeting, platform state, and the one primary action. --}}
    <header
        class="nx-hero @if (($ui['home.hero']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['home.hero']['density'] }} @endif"
        data-nx-inspect="home.hero"
        data-nx-inspect-label="Home header"
    >
        <div class="nx-hero__text">
            <h1 class="nx-hero__greeting">{{ $this->greeting() }}, {{ $this->userName() }}.</h1>
            <p class="nx-hero__state">{{ $this->stateSentence() }}</p>
        </div>

        <div class="nx-hero__actions">
            @if ($this->canCreateProject())
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('create') }}"
                    icon="heroicon-o-plus"
                >
                    {{ __('home.start_a_migration') }}
                </x-filament::button>
            @endif
            @if ($this->canViewWorkspaces())
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

    @if (! $pulse->hasAnyProject())
        {{-- First-run empty state: explains WHY a project exists (§32).
             Both v0.3.0 primary actions are preserved — "Create new project"
             AND "Import existing project" — because a fresh install may be
             bringing an existing backend rather than starting one. --}}
        <div class="nx-empty">
            <div class="nx-empty__icon">
                <x-filament::icon icon="heroicon-o-square-3-stack-3d" class="h-6 w-6" />
            </div>
            <h2 class="nx-empty__title">{{ __('home.empty_title') }}</h2>
            <p class="nx-empty__body">
                {{ __('home.empty_body') }}
            </p>
            @if ($this->canCreateProject())
                <div class="nx-empty__actions">
                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('create') }}"
                        icon="heroicon-o-plus"
                    >
                        {{ __('home.create_new_project') }}
                    </x-filament::button>
                    <x-filament::button
                        tag="a"
                        color="gray"
                        href="{{ \App\Filament\Pages\OnboardingWizard::getUrl(['start' => 'import']) }}"
                        icon="heroicon-o-arrow-down-tray"
                    >
                        {{ __('home.import_existing_project') }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    @else
        {{-- 2. Summary. Five figures, each with context, never a bare number. --}}
        @if ($ui['home.summary']['visibility'] ?? true)
            <div
                class="nx-grid nx-grid--stats @if (($ui['home.summary']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['home.summary']['density'] }} @endif"
                data-nx-inspect="home.summary"
                data-nx-inspect-label="Summary figures"
            >
                @foreach ($summary as $item)
                    <div class="nx-stat-card">
                        <span class="nx-stat-card__label">{{ $item['label'] }}</span>
                        <span @class(['nx-stat-card__value', $this->toneClass($item['tone'])])>
                            {{ $item['value'] }}
                        </span>
                        <span class="nx-stat-card__hint">{{ $item['hint'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- 3. What requires attention — before anything that is merely running. --}}
        @if ($ui['home.attention']['visibility'] ?? true)
            <section class="nx-section" data-nx-inspect="home.attention" data-nx-inspect-label="Needs attention">
                <h2 class="nx-section__title">{{ __('home.needs_attention') }}</h2>

            @if ($attention->isEmpty())
                <div class="nx-empty nx-empty--inline">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                    <p class="nx-empty__body">{{ __('home.attention_empty') }}</p>
                </div>
            @else
                <ul class="nx-attention">
                    @foreach ($attention as $row)
                        <li @class([
                            'nx-attention__item',
                            'nx-attention__item--danger' => $row['state'] === \App\Services\Product\JourneyState::BLOCKED,
                            'nx-attention__item--warning' => $row['state'] === \App\Services\Product\JourneyState::NEEDS_ATTENTION,
                        ])>
                            <x-filament::icon :icon="$row['state']->icon()" class="h-4 w-4" />
                            <div>
                                <strong>{{ $row['project']->name }} — {{ $row['reason'] }}</strong>
                                <p>{{ $row['state']->label() }}</p>
                            </div>
                            @if ($row['url'])
                                <a class="nx-link" href="{{ $row['url'] }}">{{ __('home.open') }} →</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            </section>
        @endif

        {{-- 4. What is running. --}}
        @if ($pulse->activeMigrationCount() > 0 || $readyForCutover->isNotEmpty())
            <section class="nx-section">
                <h2 class="nx-section__title">{{ __('home.in_flight') }}</h2>

                @if ($pulse->activeMigrationCount() > 0)
                    <p class="nx-section__description">
                        {{ trans_choice('home.transfers_running', $pulse->activeMigrationCount(), ['count' => $pulse->activeMigrationCount()]) }}
                    </p>
                @endif

                @if ($readyForCutover->isNotEmpty())
                    <ul class="nx-attention">
                        @foreach ($readyForCutover as $project)
                            <li class="nx-attention__item nx-attention__item--info">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                                <div>
                                    <strong>{{ __('home.ready_for_cutover_line', ['name' => $project->name]) }}</strong>
                                    <p>{{ __('home.ready_for_cutover_body') }}</p>
                                </div>
                                <a class="nx-link"
                                   href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('readiness', ['record' => $project]) }}">
                                    {{ __('home.review_cutover') }} →
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- 5. Recent projects. --}}
        @if ($ui['home.recent_projects']['visibility'] ?? true)
            <section
                class="nx-section @if (($ui['home.recent_projects']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['home.recent_projects']['density'] }} @endif"
                data-nx-inspect="home.recent_projects"
                data-nx-inspect-label="Recent projects"
            >
                <h2 class="nx-section__title">{{ __('home.recent_projects') }}</h2>
                <ul class="nx-list">
                    @foreach ($projects->take(6) as $project)
                        @php $p = \App\Services\Product\ProjectPulse::for($project); @endphp
                        <li>
                            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]) }}">
                                <span class="nx-list__name">{{ $project->name }}</span>
                                <span class="nx-tag">{{ strtoupper($project->environment ?? 'local') }}</span>
                                <span @class(['nx-status', $this->statusClass($p->overallState())])>
                                    <x-filament::icon :icon="$p->overallState()->icon()" class="h-3.5 w-3.5" />
                                    {{ $p->overallState()->label() }}
                                </span>
                                <span class="nx-list__hint">{{ $p->progressPercent() }}%</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- 6. Infrastructure LAST, and in product language (§7/§14). --}}
        @if ($ui['home.platform_health']['visibility'] ?? true)
            <section class="nx-section" data-nx-inspect="home.platform_health" data-nx-inspect-label="Platform health">
                <h2 class="nx-section__title">{{ __('home.platform_health') }}</h2>
            <div class="nx-grid nx-grid--stats">
                <div class="nx-stat-card">
                    <span class="nx-stat-card__label">{{ __('home.backups_taken') }}</span>
                    <span class="nx-stat-card__value">{{ $backups['taken'] }}</span>
                    <span class="nx-stat-card__hint">
                        @if ($backups['never'] > 0)
                            {{ trans_choice('home.backups_never', $backups['never'], ['count' => $backups['never']]) }}
                        @else
                            {{ $backups['last'] ? __('home.backups_last', ['when' => $backups['last']]) : __('home.backups_no_runs') }}
                        @endif
                    </span>
                </div>
                <div class="nx-stat-card">
                    <span class="nx-stat-card__label">{{ __('home.backups_needing_review') }}</span>
                    <span @class(['nx-stat-card__value', 'nx-stat-card__value--warning' => $backups['stale'] + $backups['unverified'] > 0])>
                        {{ $backups['stale'] + $backups['unverified'] }}
                    </span>
                    <span class="nx-stat-card__hint">
                        {{ __('home.backups_old', ['count' => $backups['stale']]) }} · {{ __('home.backups_not_restore_tested', ['count' => $backups['unverified']]) }}
                    </span>
                </div>
            </div>
            </section>
        @endif

        {{-- 7. Activity. --}}
        <section class="nx-section">
            <h2 class="nx-section__title">{{ __('home.recent_activity') }}</h2>
            @if ($activity->isEmpty())
                <div class="nx-empty nx-empty--inline">
                    <p class="nx-empty__body">
                        {{ __('home.activity_empty') }}
                    </p>
                </div>
            @else
                <ul class="nx-timeline">
                    @foreach ($activity as $entry)
                        <li class="nx-timeline__row">
                            <span class="nx-timeline__time">
                                {{ $entry['at']?->format('M j, H:i') ?? '—' }}
                            </span>
                            <span class="nx-timeline__what">
                                {{ $entry['action'] }}@if ($entry['project']) · {{ $entry['project'] }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</x-filament-panels::page>
