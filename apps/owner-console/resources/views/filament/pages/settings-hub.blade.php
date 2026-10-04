{{--
    0.6.0 Phase B — the Settings hub (audit §B2).

    One quiet destination that MAPS platform configuration. Sections are
    capability-filtered server-side (SettingsHub::sections()); every link is
    the real destination, so deep links and permissions stay intact.
--}}
<x-filament-panels::page>
    <div class="nx-hub">
        @forelse ($this->sections() as $section)
            <section class="nx-hub__section" data-hub-section="{{ $section['key'] }}">
                <h2 class="nx-hub__section-title">{{ $section['title'] }}</h2>
                <p class="nx-section__description">{{ $section['description'] }}</p>

                <div class="nx-hub__grid">
                    @foreach ($section['links'] as $link)
                        <a class="nx-hub-card" href="{{ $link['url'] }}">
                            <span class="nx-hub-card__title">
                                <x-filament::icon :icon="$section['icon']" class="h-5 w-5" />
                                {{ $link['label'] }}
                            </span>
                            <span class="nx-hub-card__desc">{{ $link['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @empty
            <x-nx.empty-state
                icon="heroicon-o-cog-6-tooth"
                :title="__('settings.title')"
                :body="__('settings.subtitle')"
            />
        @endforelse
    </div>
</x-filament-panels::page>
