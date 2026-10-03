{{--
    0.4.0 Phase F (§11) — Connector Catalog.

    A catalogue, not a table. Each card carries: icon, provider name,
    description, capabilities, migration support, Live Sync support, trust,
    status, version, and a CTA. Search and capability/trust filters are real
    controls.

    Read-only: this page never connects to a source. Connecting happens inside a
    project, where the connection is scoped and audited.
--}}
<x-filament-panels::page>
    @php
        $cards = $this->visibleCards();
        $total = $this->totalCount();
        $features = $this->featureOptions();
        $trusts = $this->trustOptions();
        $gridDensity = ($this->uiPreferences()['connectors.grid']['density'] ?? 'comfortable');
    @endphp

    {{-- Filters: a real control surface, not a line of text. --}}
    <div class="nx-catalog-controls">
        <div class="nx-search">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-4 w-4" />
            <input
                type="search"
                class="nx-search__input"
                placeholder="{{ __('connectors.search_placeholder') }}"
                aria-label="{{ __('connectors.search_placeholder') }}"
                wire:model.live.debounce.300ms="search"
            />
        </div>

        <label class="nx-check">
            <input type="checkbox" wire:model.live="onlyReady" />
            <span>{{ __('connectors.ready_only') }}</span>
        </label>
    </div>

    <div class="nx-filters">
        <div class="nx-filters__group" role="group" aria-label="Filter by capability">
            <span class="nx-filters__label">Capability</span>
            @foreach ($features as $feature)
                <button
                    type="button"
                    wire:click="toggleFeature('{{ $feature['key'] }}')"
                    @class(['nx-chip', 'is-on' => in_array($feature['key'], $this->featureFilters, true)])
                    aria-pressed="{{ in_array($feature['key'], $this->featureFilters, true) ? 'true' : 'false' }}"
                >{{ $feature['label'] }} <span class="nx-chip__count">{{ $feature['count'] }}</span></button>
            @endforeach
        </div>

        <div class="nx-filters__group" role="group" aria-label="Filter by trust level">
            <span class="nx-filters__label">Trust</span>
            @foreach ($trusts as $trust)
                <button
                    type="button"
                    wire:click="toggleTrust('{{ $trust['key'] }}')"
                    @class(['nx-chip', 'is-on' => in_array($trust['key'], $this->trustFilters, true)])
                    aria-pressed="{{ in_array($trust['key'], $this->trustFilters, true) ? 'true' : 'false' }}"
                >{{ $trust['label'] }} <span class="nx-chip__count">{{ $trust['count'] }}</span></button>
            @endforeach
        </div>

        @if ($this->hasActiveFilters())
            <button type="button" class="nx-link" wire:click="clearFilters">{{ __('connectors.clear_filters') }}</button>
        @endif
    </div>

    <p class="nx-filters__summary" role="status" aria-live="polite">
        {{ trans_choice('connectors.showing', $total, ['shown' => count($cards), 'total' => $total]) }}
    </p>

    @if ($cards === [])
        {{-- Empty state explains WHY the catalogue exists (§32). --}}
        <div class="nx-empty">
            <div class="nx-empty__icon">
                <x-filament::icon icon="heroicon-o-puzzle-piece" class="h-6 w-6" />
            </div>
            @if ($total === 0)
                <h2 class="nx-empty__title">{{ __('connectors.empty_none') }}</h2>
                <p class="nx-empty__body">
                    {{ __('connectors.empty_none_body') }}
                </p>
            @else
                <h2 class="nx-empty__title">{{ __('connectors.empty_filter') }}</h2>
                <p class="nx-empty__body">
                    {{ __('connectors.empty_filter_body', ['total' => $total]) }}
                </p>
                <x-filament::button color="gray" wire:click="clearFilters">
                    {{ __('connectors.clear_filters') }}
                </x-filament::button>
            @endif
        </div>
    @else
        <div
            class="nx-grid nx-grid--connectors @if ($gridDensity !== 'comfortable') nx-density--{{ $gridDensity }} @endif"
            data-nx-inspect="connectors.grid"
            data-nx-inspect-label="Connector catalogue"
        >
            @foreach ($cards as $card)
                <article class="nx-connector">
                    <header class="nx-connector__head">
                        <span class="nx-connector__icon" aria-hidden="true">
                            <x-filament::icon :icon="$card['icon']" class="h-5 w-5" />
                        </span>
                        <div class="nx-connector__identity">
                            <h2 class="nx-connector__name">{{ $card['name'] }}</h2>
                            <p class="nx-connector__vendor">
                                {{ $card['author'] }} · v{{ $card['version'] }}
                            </p>
                        </div>
                        <span @class(['nx-status', 'nx-status--'.$card['status_tone']])>
                            <x-filament::icon
                                icon="{{ $card['enabled'] ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle' }}"
                                class="h-3.5 w-3.5" />
                            {{ $card['status_label'] }}
                        </span>
                    </header>

                    <p class="nx-connector__description">{{ $card['description'] }}</p>

                    {{-- The two headline capabilities the mission asks for. --}}
                    <dl class="nx-connector__flags">
                        <div>
                            <dt>{{ __('wizard.source_migration') }}</dt>
                            <dd @class(['nx-flag', 'is-yes' => $card['migration'], 'is-no' => ! $card['migration']])>
                                <x-filament::icon
                                    icon="{{ $card['migration'] ? 'heroicon-o-check' : 'heroicon-o-x-mark' }}"
                                    class="h-3.5 w-3.5" />
                                {{ $card['migration'] ? __('common.status_supported') : __('common.status_not_supported') }}
                            </dd>
                        </div>
                        <div>
                            <dt>{{ __('common.live_sync') }}</dt>
                            <dd @class(['nx-flag', 'is-yes' => $card['live_sync'], 'is-no' => ! $card['live_sync']])>
                                <x-filament::icon
                                    icon="{{ $card['live_sync'] ? 'heroicon-o-check' : 'heroicon-o-x-mark' }}"
                                    class="h-3.5 w-3.5" />
                                {{ $card['live_sync'] ? __('common.status_supported') : __('common.status_not_supported') }}
                            </dd>
                        </div>
                        <div>
                            <dt>{{ __('connectors.trust') }}</dt>
                            <dd @class(['nx-status', 'nx-status--'.$card['trust_tone']])
                                title="{{ $card['trust_hint'] }}">
                                {{ $card['trust_label'] }}
                            </dd>
                        </div>
                    </dl>

                    @if ($card['features'] !== [])
                        <ul class="nx-connector__features">
                            @foreach ($card['features'] as $feature)
                                <li class="nx-feature" title="{{ $feature['hint'] }}">
                                    <x-filament::icon icon="heroicon-o-check" class="h-3 w-3" />
                                    {{ $feature['label'] }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($card['read_only'])
                        <p class="nx-connector__safety">
                            <x-filament::icon icon="heroicon-o-lock-closed" class="h-3.5 w-3.5" />
                            {{ __('connectors.reads_only') }}
                        </p>
                    @endif

                    <footer class="nx-connector__foot">
                        <details class="nx-connector__advanced">
                            <summary>{{ __('common.advanced') }}</summary>
                            <dl class="nx-connector__meta">
                                <dt>{{ __('connectors.key') }}</dt><dd class="nx-code">{{ $card['key'] }}</dd>
                                <dt>{{ __('connectors.import_flow') }}</dt><dd class="nx-code">{{ $card['import_flow'] }}</dd>
                                <dt>{{ __('connectors.category') }}</dt><dd class="nx-code">{{ $card['category'] }}</dd>
                                <dt>{{ __('connectors.capabilities') }}</dt>
                                <dd class="nx-code">{{ implode(', ', $card['raw_capabilities']) }}</dd>
                            </dl>
                            @if ($card['docs_url'])
                                <a class="nx-link" href="{{ $card['docs_url'] }}" rel="noopener noreferrer" target="_blank">
                                    {{ __('connectors.view_docs') }} ↗
                                </a>
                            @endif
                        </details>

                        {{-- CTA. Connecting needs a project, so the catalogue
                             routes into one rather than pretending it can
                             connect from here. --}}
                        @if (! $card['enabled'])
                            <span class="nx-connector__disabled">{{ __('connectors.not_available') }}</span>
                        @elseif ($this->hasConnectTarget() && $this->connectUrl())
                            <a class="nx-link" href="{{ $this->connectUrl() }}">
                                {{ __('connectors.connect_in_project') }} →
                            </a>
                        @else
                            <span class="nx-connector__disabled">{{ __('connectors.create_project_to_connect') }}</span>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>
    @endif

    <p class="nx-section__description">
        Local catalogue only. Remote marketplace listings and billing are not part of this
        release. Packages installed through the installer carry verified sha256 checksums;
        cryptographic signature verification is planned.
    </p>
</x-filament-panels::page>
