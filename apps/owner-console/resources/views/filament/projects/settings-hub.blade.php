{{--
    0.6.0 Phase D (§D8) — the PROJECT SETTINGS hub grid.

    A quiet entry structure for the settings tab: each card is the real
    destination (routes preserved), filtered server-side by that page's own
    canAccess(). Grouping: Environment, Connections, Secrets, Team & access,
    Advanced. General (the project profile) stays in the infolist above.
--}}
@php
    $groups = [
        'environment' => [
            'title' => __('projects.hub_environments'),
            'description' => __('projects.hub_environments_desc'),
            'pages' => ['environments'],
        ],
        'connections' => [
            'title' => __('projects.hub_connections'),
            'description' => __('projects.hub_connections_desc'),
            'pages' => ['connections'],
        ],
        'secrets' => [
            'title' => __('projects.hub_secrets'),
            'description' => __('projects.hub_secrets_desc'),
            'pages' => ['secrets'],
        ],
        'team' => [
            'title' => __('projects.hub_team'),
            'description' => __('projects.hub_team_desc'),
            'pages' => ['users', 'roles', 'permissions'],
        ],
        'advanced' => [
            'title' => __('projects.hub_advanced'),
            'description' => __('projects.hub_advanced_desc'),
            'pages' => ['db-advanced'],
        ],
    ];

    // Each card names its real destination (nav vocabulary, translated).
    $pageLabels = [
        'environments' => __('nav.environments'),
        'connections' => __('nav.connections'),
        'secrets' => __('nav.secrets'),
        'users' => __('nav.users'),
        'roles' => __('nav.roles'),
        'permissions' => __('nav.permissions'),
        'db-advanced' => __('projects.hub_advanced'),
    ];
@endphp

<div class="nx-hub" data-nx-inspect="project.settings.hub" data-nx-inspect-label="Project settings hub">
    <p class="nx-section__description">{{ __('projects.settings_intro') }}</p>

    @foreach ($groups as $key => $group)
        @php
            // mapWithKeys keeps the page slug as the key so labels resolve.
            $groupEntries = collect($group['pages'])
                ->filter(fn (string $page): bool => isset($entries[$page]))
                ->mapWithKeys(fn (string $page): array => [$page => $entries[$page]]);
        @endphp
        @if ($groupEntries->isNotEmpty())
            <section class="nx-hub__section" data-hub-section="{{ $key }}">
                <h2 class="nx-hub__section-title">{{ $group['title'] }}</h2>
                <p class="nx-section__description">{{ $group['description'] }}</p>

                <div class="nx-hub__grid">
                    @foreach ($groupEntries as $page => $entry)
                        <a class="nx-hub-card" href="{{ $entry['url'] }}">
                            <span class="nx-hub-card__title">
                                <x-filament::icon :icon="$entry['icon']" class="h-5 w-5" />
                                {{ $pageLabels[$page] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
</div>
