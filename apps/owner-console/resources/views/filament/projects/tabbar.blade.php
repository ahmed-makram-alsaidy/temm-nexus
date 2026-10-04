{{--
    0.6.0 Phase B — the project context tab bar (audit §B4/§B5/§B6).

    Rendered at CONTENT_START on every project-scoped page. The platform
    sidebar stays quiet; the project speaks for itself with ONE horizontal
    primary tab row (Overview · Migration · Data · Access · Build · Operate
    · Settings) and the active tab's secondary destinations.

    Identity lives in the topbar project chip; the environment switcher is
    the one context control that stays here, at the end of the primary row.
--}}
@php
    $resolved = \App\Filament\Support\ProjectTabs::resolve($project, $activePage);
    $envs = $project->environments()->orderBy('id')->get();
    $activeEnv = \App\Services\ControlPlane\EnvironmentContext::active($project);
@endphp

<nav class="nx-tabs" aria-label="{{ __('nav.project_context') }}">
    <div class="nx-tabs__primary">
        @foreach ($resolved['tabs'] as $tab)
            <a
                class="nx-tabs__tab"
                href="{{ $tab['url'] }}"
                @if ($tab['active']) aria-current="page" @endif
            >{{ $tab['label'] }}</a>
        @endforeach

        @if ($envs->count() > 0)
            <details class="nx-tabs__env">
                <summary class="nx-tabs__env-btn" title="{{ __('nav.active_environment') }}">
                    <span class="cp-dot {{ $activeEnv->type === 'production' ? 'is-danger' : ($activeEnv->type === 'staging' ? 'is-warn' : 'is-healthy') }}"></span>
                    <span>{{ $activeEnv->name }}</span>
                    <x-filament::icon icon="heroicon-o-chevron-up-down" class="h-3 w-3" />
                </summary>
                <div class="nx-tabs__env-menu" role="menu">
                    @foreach ($envs as $envOption)
                        <a
                            role="menuitem"
                            href="{{ route('control-plane.environment-switch', ['project' => $project, 'environment' => $envOption]) }}"
                            @if ($envOption->is($activeEnv)) aria-current="true" @endif
                            @if ($envOption->status !== 'active') style="opacity:.55" @endif
                        >
                            <span>{{ $envOption->name }}{{ $envOption->status !== 'active' ? ' ('.__('nav.inactive').')' : '' }}</span>
                            <span class="nx-tag">{{ strtoupper($envOption->type) }}</span>
                        </a>
                    @endforeach
                    <a role="menuitem" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('environments', ['record' => $project]) }}">
                        <span>{{ __('nav.manage_environments') }}</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </details>
        @endif
    </div>

    @if (count($resolved['secondary']) > 1)
        <div class="nx-tabs__secondary">
            @foreach ($resolved['secondary'] as $item)
                <a
                    class="nx-tabs__subtab"
                    href="{{ $item['url'] }}"
                    @if ($item['active']) aria-current="page" @endif
                >{{ $item['label'] }}</a>
            @endforeach
        </div>
    @endif
</nav>
