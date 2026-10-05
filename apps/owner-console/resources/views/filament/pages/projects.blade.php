{{--
    0.6.0 Phase D (§D1) — PROJECTS INDEX as a product view.

    Order on the page: title + summary → ONE creation action (header) →
    filters/search → project cards (or optional compact rows) → pagination.
    A card answers "which project needs me / what stage is it in / how do I
    open it" in five seconds; no slug, DB name, API domain, deploy status,
    internal id or raw enum is ever part of the default face (§D1) — those
    live in project settings and the overview's technical details.

    Empty states teach (§D1): creation-capable users get the wizard CTA;
    viewers get an honest explanation; a filtered-empty view offers the
    way out instead of "No records".
--}}
    @php
        $environmentLabels = [
            'local' => __('projects.env_local'),
            'development' => __('projects.env_development'),
            'staging' => __('projects.env_staging'),
            'production' => __('projects.env_production'),
        ];
        $environmentLabel = fn (?string $env): string => $environmentLabels[$env ?? 'local'] ?? __('projects.env_local');
    @endphp

    @if ($error)
        {{-- §D11: the index degrades to the Phase A error pattern, never a raw 500. --}}
        <x-nx.error :payload="\App\Support\NxError::forException(
            __('foundation.error_title'),
            __('projects.error_body'),
            $error,
        )" />
    @elseif ($total === 0)
        @if ($canCreate)
            <x-nx.empty-state
                icon="heroicon-o-square-3-stack-3d"
                :title="__('projects.empty_title')"
                :body="__('projects.empty_body')"
                :actionUrl="\App\Filament\Pages\NewProjectWizard::getUrl()"
                :actionLabel="__('projects.empty_cta')"
            />
        @else
            <x-nx.empty-state
                icon="heroicon-o-square-3-stack-3d"
                :title="__('projects.empty_viewer_title')"
                :body="__('projects.empty_viewer_body')"
            />
        @endif
    @else
        {{-- Summary: the attention/in-progress context the fold must carry (§D14). --}}
        <p class="nx-projects-summary">
            @php
                $summaryParts = [];
                $summaryParts[] = (string) trans_choice('projects.count_projects', $counts['all'], ['count' => $counts['all']]);
                if ($counts['attention'] > 0) {
                    $summaryParts[] = (string) trans_choice('projects.count_needs_attention', $counts['attention'], ['count' => $counts['attention']]);
                }
                if ($counts['in_progress'] > 0) {
                    $summaryParts[] = (string) trans_choice('projects.count_in_progress', $counts['in_progress'], ['count' => $counts['in_progress']]);
                }
            @endphp
            {{ implode(' · ', $summaryParts) }}
        </p>

        {{-- Filters (§D1): a handful of useful ones + search + view mode. --}}
        <div class="nx-filterbar">
            <nav class="nx-filterbar__groups" aria-label="{{ __('projects.filter_all') }}">
                @foreach ([
                    'all' => __('projects.filter_all'),
                    'attention' => __('projects.filter_attention'),
                    'in_progress' => __('projects.filter_in_progress'),
                    'completed' => __('projects.filter_completed'),
                ] as $key => $label)
                    <a
                        href="#"
                        wire:click="$set('filter', '{{ $key }}')"
                        @class(['nx-filterbar__chip', 'is-active' => $filter === $key])
                    >{{ $label }}</a>
                @endforeach
            </nav>

            <div class="nx-filterbar__tools">
                <label class="nx-filterbar__search">
                    <span class="sr-only">{{ __('projects.search_label') }}</span>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('projects.search_placeholder') }}"
                        aria-label="{{ __('projects.search_label') }}"
                    />
                </label>

                <select wire:model.live="filterEnvironment" aria-label="{{ __('projects.filter_environment') }}"
                        @class(['nx-filterbar__select', 'is-placeholder' => $filterEnvironment === ''])>
                    <option value="">{{ __('projects.filter_all_environments') }}</option>
                    @foreach (['production', 'staging', 'development', 'local'] as $env)
                        <option value="{{ $env }}">{{ $environmentLabel($env) }}</option>
                    @endforeach
                </select>

                @if ($workspaces->isNotEmpty())
                    <select wire:model.live="filterWorkspace" aria-label="{{ __('projects.filter_workspace') }}"
                            @class(['nx-filterbar__select', 'is-placeholder' => $filterWorkspace === ''])>
                        <option value="">{{ __('projects.filter_all_workspaces') }}</option>
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                        @endforeach
                    </select>
                @endif

                <div class="nx-filterbar__modes" role="group" aria-label="{{ __('projects.view_cards') }}">
                    <button type="button" wire:click="$set('view', 'cards')"
                            @class(['is-active' => $mode === 'cards']) title="{{ __('projects.view_cards') }}">
                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-4 w-4" />
                        <span>{{ __('projects.view_cards') }}</span>
                    </button>
                    <button type="button" wire:click="$set('view', 'compact')"
                            @class(['is-active' => $mode === 'compact']) title="{{ __('projects.view_compact') }}">
                        <x-filament::icon icon="heroicon-o-list-bullet" class="h-4 w-4" />
                        <span>{{ __('projects.view_compact') }}</span>
                    </button>
                </div>
            </div>
        </div>

        @if ($rows->isEmpty())
            <x-nx.empty-state
                compact
                icon="heroicon-o-inbox"
                :title="__('projects.empty_no_matches_title')"
                :body="__('projects.empty_no_matches_body')"
            />
            <p class="nx-section__more">
                <a class="nx-link" href="#" wire:click="clearFilters">
                    {{ __('projects.clear_filters') }}
                </a>
            </p>
        @elseif ($mode === 'compact')
            {{-- Optional density for large installations (§D1) — still a calm
                 product view: no technical columns, ever. --}}
            <div class="nx-section">
                <table class="nx-projects-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('projects.col_project') }}</th>
                            <th scope="col">{{ __('projects.col_workspace') }}</th>
                            <th scope="col">{{ __('projects.col_environment') }}</th>
                            <th scope="col">{{ __('projects.col_stage') }}</th>
                            <th scope="col">{{ __('projects.col_status') }}</th>
                            <th scope="col">{{ __('projects.col_progress') }}</th>
                            <th scope="col">{{ __('projects.col_activity') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('projects.card_open') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><a class="nx-projects-table__name" href="{{ ($openUrl)($row['project']) }}">{{ $row['project']->name }}</a></td>
                                <td>{{ $row['workspace'] ?? '—' }}</td>
                                <td><span class="nx-tag">{{ $environmentLabel($row['project']->environment) }}</span></td>
                                <td>{{ $row['stage']->label() }}</td>
                                <td>
                                    <span @class(['nx-status', 'nx-status--'.$row['state']->tone()])>
                                        <x-filament::icon :icon="$row['state']->icon()" class="h-3.5 w-3.5" />
                                        {{ $row['state']->label() }}
                                    </span>
                                </td>
                                <td class="nx-num">{{ $row['progress'] }}%</td>
                                <td>
                                    @if ($row['lastActivityAt'])
                                        {{ __('projects.last_active_at', ['when' => $row['lastActivityAt']->diffForHumans()]) }}
                                    @else
                                        {{ __('projects.last_active_never') }}
                                    @endif
                                </td>
                                <td><a class="nx-link" href="{{ ($openUrl)($row['project']) }}">{{ __('projects.card_open') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- The default face (§D1): cards with decision information only. --}}
            <div class="nx-grid nx-grid--projects">
                @foreach ($rows as $row)
                    @php
                        $attention = in_array($row['state'], [\App\Services\Product\JourneyState::BLOCKED, \App\Services\Product\JourneyState::NEEDS_ATTENTION], true);
                    @endphp
                    <a class="nx-card nx-card--link @if ($attention) nx-card--attention @endif"
                       href="{{ ($openUrl)($row['project']) }}">
                        <div class="nx-card__header">
                            <h3 class="nx-card__title">{{ $row['project']->name }}</h3>
                            <span @class(['nx-status', 'nx-status--'.$row['state']->tone()])>
                                <x-filament::icon :icon="$row['state']->icon()" class="h-3.5 w-3.5" />
                                {{ $row['state']->label() }}
                            </span>
                        </div>

                        <p class="nx-card__meta">
                            @if ($row['workspace'])
                                {{ $row['workspace'] }} ·
                            @endif
                            {{ $environmentLabel($row['project']->environment) }}
                        </p>

                        <div class="nx-bar" role="progressbar" aria-valuenow="{{ $row['progress'] }}"
                             aria-valuemin="0" aria-valuemax="100"
                             aria-label="{{ __('projects.fact_progress') }} — {{ $row['project']->name }}">
                            <div class="nx-bar__fill" style="width: {{ $row['progress'] }}%"></div>
                        </div>

                        <p class="nx-card__hint">
                            {{ $row['progress'] }}% · {{ __('projects.col_stage') }}: {{ $row['stage']->label() }}
                        </p>

                        <p class="nx-card__hint">
                            @if ($row['attentionReason'])
                                {{ $row['attentionReason'] }}
                            @elseif ($row['lastActivityAt'])
                                {{ __('projects.last_active_at', ['when' => $row['lastActivityAt']->diffForHumans()]) }}
                            @else
                                {{ __('projects.last_active_never') }}
                            @endif
                        </p>

                        <span class="nx-card__open">{{ __('projects.card_open') }} →</span>
                    </a>
                @endforeach
            </div>
        @endif

        @if ($lastPage > 1)
            <nav class="nx-pagination" aria-label="{{ __('projects.pagination_page', ['current' => $page, 'last' => $lastPage]) }}">
                <button type="button" wire:click="$set('pageNumber', {{ max(1, $page - 1) }})" @if ($page <= 1) disabled @endif>
                    ← {{ __('projects.pagination_previous') }}
                </button>
                <span class="nx-pagination__status">
                    {{ __('projects.pagination_page', ['current' => $page, 'last' => $lastPage]) }}
                </span>
                <button type="button" wire:click="$set('pageNumber', {{ min($lastPage, $page + 1) }})" @if ($page >= $lastPage) disabled @endif>
                    {{ __('projects.pagination_next') }} →
                </button>
            </nav>
        @endif
    @endif
