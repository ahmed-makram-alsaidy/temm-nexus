{{--
    0.4.0 Phase C — Clients & Workspaces index.

    The PLATFORM-scope entry to the middle scope of the product.
    Every value here comes from Access-scoped queries; nothing is read from the
    request. See docs/product/INFORMATION_ARCHITECTURE.md §5.
--}}
<x-filament-panels::page>
    @php
        $workspaces = $this->workspaces();
        $projectsByWorkspace = $this->projectsByWorkspace();
        $ungrouped = $this->ungroupedCount();
    @endphp

    @if ($workspaces->isEmpty() && $ungrouped === 0)
        {{-- Empty state: explains WHY a workspace exists (§32). --}}
        <div class="nx-empty">
            <div class="nx-empty__icon">
                <x-filament::icon icon="heroicon-o-building-office-2" class="h-6 w-6" />
            </div>
            <h2 class="nx-empty__title">{{ __('workspaces.empty_title') }}</h2>
            <p class="nx-empty__body">
                {{ __('workspaces.subtitle') }}
            </p>
            @if ($this->canCreate())
                <x-filament::button wire:click="mountAction('createWorkspace')" icon="heroicon-o-plus">
                    {{ __('workspaces.new_workspace') }}
                </x-filament::button>
            @endif
        </div>
    @else
        <div class="nx-grid nx-grid--workspaces">
            @foreach ($workspaces as $workspace)
                @php
                    $stats = $this->statsFor($workspace);
                    $projects = $projectsByWorkspace->get($workspace->id, collect());
                    $owner = $this->ownerName($workspace);
                @endphp

                <article class="nx-card nx-card--workspace">
                    <header class="nx-card__header">
                        <div class="nx-card__identity">
                            <span class="nx-card__mark" aria-hidden="true">
                                {{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}
                            </span>
                            <div>
                                <h2 class="nx-card__title">
                                    <a href="{{ \App\Filament\Pages\WorkspaceDetail::urlFor($workspace) }}">
                                        {{ $workspace->name }}
                                    </a>
                                </h2>
                                <p class="nx-card__meta">
                                    {{ $workspace->displayKind() }}
                                    @if ($workspace->is_default)
                                        · <span class="nx-tag">{{ __('workspaces.default') }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <span @class([
                            'nx-status',
                            'nx-status--success' => $workspace->health_status === 'healthy',
                            'nx-status--warning' => $workspace->health_status === 'degraded',
                            'nx-status--danger' => $workspace->health_status === 'unhealthy',
                            'nx-status--neutral' => ! in_array($workspace->health_status, ['healthy', 'degraded', 'unhealthy'], true),
                        ])>
                            <x-filament::icon
                                icon="{{ match ($workspace->health_status) {
                                    'healthy' => 'heroicon-o-check-circle',
                                    'degraded' => 'heroicon-o-exclamation-triangle',
                                    'unhealthy' => 'heroicon-o-no-symbol',
                                    default => 'heroicon-o-question-mark-circle',
                                } }}"
                                class="h-4 w-4"
                            />
                            {{ $workspace->healthLabel() }}
                        </span>
                    </header>

                    @if ($workspace->description)
                        <p class="nx-card__description">{{ $workspace->description }}</p>
                    @endif

                    <dl class="nx-stats">
                        <div class="nx-stat">
                            <dt>{{ __('workspaces.projects') }}</dt>
                            <dd>{{ $stats['projects'] }}</dd>
                        </div>
                        <div class="nx-stat">
                            <dt>{{ __('workspaces.members') }}</dt>
                            <dd>{{ $stats['members'] }}</dd>
                        </div>
                        <div class="nx-stat">
                            <dt>{{ __('home.needs_attention') }}</dt>
                            <dd @class(['nx-stat__value--warning' => $stats['attention'] > 0])>
                                {{ $stats['attention'] }}
                            </dd>
                        </div>
                    </dl>

                    @if ($projects->isNotEmpty())
                        <ul class="nx-list nx-list--compact">
                            @foreach ($projects->take(4) as $project)
                                <li>
                                    <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]) }}">
                                        <span @class([
                                            'nx-dot',
                                            'nx-dot--success' => $project->health_status === 'healthy',
                                            'nx-dot--danger' => $project->health_status === 'unhealthy',
                                            'nx-dot--neutral' => ! in_array($project->health_status, ['healthy', 'unhealthy'], true),
                                        ]) aria-hidden="true"></span>
                                        {{ $project->name }}
                                        {{-- Status is never colour-only: this text carries the meaning. --}}
                                        <span class="nx-list__hint">{{ $project->healthLabel() }}</span>
                                    </a>
                                </li>
                            @endforeach
                            @if ($projects->count() > 4)
                                <li class="nx-list__more">
                                    +{{ $projects->count() - 4 }} more
                                </li>
                            @endif
                        </ul>
                    @else
                        <p class="nx-card__hint">{{ __('workspaces.no_projects_body') }}</p>
                    @endif

                    <footer class="nx-card__footer">
                        <span class="nx-card__hint">{{ $owner ? 'Owner: '.$owner : 'No owner assigned' }}</span>
                        <a class="nx-link" href="{{ \App\Filament\Pages\WorkspaceDetail::urlFor($workspace) }}">
                            Open workspace →
                        </a>
                    </footer>
                </article>
            @endforeach
        </div>

        @if ($ungrouped > 0)
            {{-- Legacy projects are surfaced, not hidden. --}}
            <div class="nx-notice nx-notice--info">
                <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" />
                <div>
                    <strong>{{ trans_choice('workspaces.ungrouped_count', $ungrouped, ['count' => $ungrouped]) }}</strong>
                    <p>{{ __('workspaces.ungrouped_body') }}</p>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
