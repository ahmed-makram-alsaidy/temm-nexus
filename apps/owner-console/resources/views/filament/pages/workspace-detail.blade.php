{{--
    0.4.0 Phase C — WORKSPACE scope dashboard (0.4.0 §8).

    Composition: identity · health summary · projects · members · attention ·
    primary actions. The workspace is authorised in mount(); this view only
    renders what that authorisation already allowed.
--}}
<x-filament-panels::page>
    @php
        $workspace = $this->workspace;
        $projects = $this->projects();
        $members = $this->members();
        $health = $this->healthSummary();
        $attention = $this->attentionItems();
        // Phase I — this user's appearance preferences for the workspace page.
        $ui = $this->uiPreferences();
    @endphp

    @if ($workspace)
        {{-- Scope banner: makes the active scope unmistakable (§2). --}}
        <div class="nx-scope-banner">
            <span class="nx-scope-banner__label">Scope</span>
            <span class="nx-scope-banner__value">Workspace — {{ $workspace->name }}</span>
        </div>

        <div class="nx-grid nx-grid--stats">
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Projects</span>
                <span class="nx-stat-card__value">{{ $health['total'] }}</span>
                <span class="nx-stat-card__hint">{{ $health['healthy'] }} healthy</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Needs attention</span>
                <span @class(['nx-stat-card__value', 'nx-stat-card__value--warning' => $health['attention'] > 0])>
                    {{ $health['attention'] }}
                </span>
                <span class="nx-stat-card__hint">{{ $health['unknown'] }} without a result</span>
            </div>
            <div class="nx-stat-card">
                <span class="nx-stat-card__label">Members</span>
                <span class="nx-stat-card__value">{{ $members->count() }}</span>
                <span class="nx-stat-card__hint">with access to this workspace</span>
            </div>
        </div>

        {{-- Attention BEFORE projects: what needs me comes first (§7/§8). --}}
        <section class="nx-section">
            <h2 class="nx-section__title">Needs attention</h2>
            @if ($attention === [])
                <div class="nx-empty nx-empty--inline">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                    <p class="nx-empty__body">Nothing needs you right now.</p>
                </div>
            @else
                <ul class="nx-attention">
                    @foreach ($attention as $item)
                        <li @class(['nx-attention__item', 'nx-attention__item--'.$item['severity']])>
                            <x-filament::icon
                                icon="{{ $item['severity'] === 'danger' ? 'heroicon-o-no-symbol' : ($item['severity'] === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-information-circle') }}"
                                class="h-4 w-4"
                            />
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
            @endif
        </section>

        <section
            class="nx-section @if (($ui['workspace.projects']['density'] ?? 'comfortable') !== 'comfortable') nx-density--{{ $ui['workspace.projects']['density'] }} @endif"
            data-nx-inspect="workspace.projects"
            data-nx-inspect-label="Workspace projects"
        >
            <h2 class="nx-section__title">Projects</h2>
            @if ($projects->isEmpty())
                <div class="nx-empty">
                    <div class="nx-empty__icon">
                        <x-filament::icon icon="heroicon-o-square-3-stack-3d" class="h-6 w-6" />
                    </div>
                    <h3 class="nx-empty__title">No projects in this workspace</h3>
                    <p class="nx-empty__body">
                        A project is one backend you migrate — a website, an API, a CRM.
                        Create one to start connecting a source.
                    </p>
                    @if ($this->canCreateProject())
                        <x-filament::button
                            tag="a"
                            href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('create', ['workspace' => $workspace->id]) }}"
                            icon="heroicon-o-plus"
                        >
                            Create a project
                        </x-filament::button>
                    @endif
                </div>
            @else
                <div class="nx-grid nx-grid--projects">
                    @foreach ($projects as $project)
                        @php $pulse = \App\Services\Product\ProjectPulse::for($project); @endphp
                        <a class="nx-card nx-card--link"
                           href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]) }}">
                            <div class="nx-card__header">
                                <h3 class="nx-card__title">{{ $project->name }}</h3>
                                <span @class(['nx-status', 'nx-status--'.$pulse->overallState()->tone()])>
                                    <x-filament::icon :icon="$pulse->overallState()->icon()" class="h-3.5 w-3.5" />
                                    {{ $pulse->overallState()->label() }}
                                </span>
                            </div>
                            <p class="nx-card__meta">
                                {{ strtoupper($project->environment ?? 'local') }}
                                @if ($project->domain)
                                    · {{ $project->domain }}
                                @endif
                            </p>
                            {{-- §8: the workspace must show migration activity, not just names. --}}
                            <div class="nx-bar" role="progressbar" aria-valuenow="{{ $pulse->progressPercent() }}"
                                 aria-valuemin="0" aria-valuemax="100"
                                 aria-label="{{ $project->name }} migration progress">
                                <div class="nx-bar__fill" style="width: {{ $pulse->progressPercent() }}%"></div>
                            </div>
                            <p class="nx-card__hint">
                                {{ $pulse->progressPercent() }}% · {{ $pulse->currentStage()->label() }}
                            </p>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($ui['workspace.members']['visibility'] ?? true)
            <section class="nx-section" data-nx-inspect="workspace.members" data-nx-inspect-label="Workspace members">
                <h2 class="nx-section__title">Members</h2>
            @if ($members->isEmpty())
                <div class="nx-empty nx-empty--inline">
                    <p class="nx-empty__body">
                        No one has been added to this workspace yet. Add a teammate to give them
                        access to every project inside it.
                    </p>
                </div>
            @else
                <ul class="nx-people">
                    @foreach ($members as $membership)
                        <li class="nx-person">
                            <span class="nx-avatar" aria-hidden="true">
                                {{ $membership->user?->initials() ?? '?' }}
                            </span>
                            <div>
                                <strong>{{ $membership->user?->name ?? 'Unknown' }}</strong>
                                <span class="nx-person__role">
                                    {{ \App\Services\Access\Roles::label($membership->role) }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
