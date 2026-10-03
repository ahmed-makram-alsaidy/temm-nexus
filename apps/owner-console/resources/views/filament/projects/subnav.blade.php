{{-- Project workspace sidebar. One primary navigation; no horizontal bar. --}}
@php
    $groups = [
        __('nav.group_database') => [
            'database' => ['label' => __('nav.tables'), 'icon' => 'heroicon-o-circle-stack'],
            'erd' => ['label' => __('nav.erd'), 'icon' => 'heroicon-o-share'],
            'sql' => ['label' => __('nav.sql_editor'), 'icon' => 'heroicon-o-command-line'],
            'table-schema' => ['label' => __('nav.schema'), 'icon' => 'heroicon-o-document-duplicate'],
            'db-functions' => ['label' => __('nav.functions'), 'icon' => 'heroicon-o-variable'],
            'db-advanced' => ['label' => __('nav.inspector'), 'icon' => 'heroicon-o-adjustments-horizontal'],
            'migrations' => ['label' => __('nav.migrations'), 'icon' => 'heroicon-o-arrows-right-left'],
            'schema-diff' => ['label' => __('nav.schema_diff'), 'icon' => 'heroicon-o-scissors'],
            'db-health' => ['label' => __('nav.db_health'), 'icon' => 'heroicon-o-heart'],
            'connections' => ['label' => __('nav.connections'), 'icon' => 'heroicon-o-globe-alt'],
        ],
        __('nav.group_authentication') => [
            'users' => ['label' => __('nav.users'), 'icon' => 'heroicon-o-users'],
            'roles' => ['label' => __('nav.roles'), 'icon' => 'heroicon-o-key'],
            'permissions' => ['label' => __('nav.permissions'), 'icon' => 'heroicon-o-shield-check'],
            'sessions' => ['label' => __('nav.sessions'), 'icon' => 'heroicon-o-computer-desktop'],
            'auth-security' => ['label' => __('nav.providers'), 'icon' => 'heroicon-o-lock-closed'],
            'secrets' => ['label' => __('nav.secrets'), 'icon' => 'heroicon-o-eye-slash'],
        ],
        __('nav.group_build') => [
            'storage' => ['label' => __('nav.storage'), 'icon' => 'heroicon-o-folder'],
            'api' => ['label' => __('nav.api'), 'icon' => 'heroicon-o-bolt'],
            'connect' => ['label' => __('nav.connect'), 'icon' => 'heroicon-o-device-phone-mobile'],
            'keys' => ['label' => __('nav.api_keys'), 'icon' => 'heroicon-o-finger-print'],
            'functions' => ['label' => __('nav.functions'), 'icon' => 'heroicon-o-code-bracket'],
            'realtime' => ['label' => __('nav.realtime'), 'icon' => 'heroicon-o-signal'],
            'webhooks' => ['label' => __('nav.webhooks'), 'icon' => 'heroicon-o-link'],
            'scheduler' => ['label' => __('nav.scheduler'), 'icon' => 'heroicon-o-calendar-days'],
        ],
        __('nav.group_operate') => [
            'logs' => ['label' => __('nav.logs'), 'icon' => 'heroicon-o-document-text'],
            'queues' => ['label' => __('nav.queues'), 'icon' => 'heroicon-o-queue-list'],
            'monitoring' => ['label' => __('nav.monitoring'), 'icon' => 'heroicon-o-chart-bar'],
            'backups' => ['label' => __('nav.backups'), 'icon' => 'heroicon-o-archive-box'],
            'infrastructure' => ['label' => __('nav.infrastructure'), 'icon' => 'heroicon-o-server-stack'],
            'resources' => ['label' => __('nav.resources'), 'icon' => 'heroicon-o-cpu-chip'],
            'readiness' => ['label' => __('nav.readiness'), 'icon' => 'heroicon-o-clipboard-document-check'],
        ],
        __('nav.group_project') => [
            'environments' => ['label' => __('nav.environments'), 'icon' => 'heroicon-o-squares-2x2'],
            'migration-center' => ['label' => __('nav.migration_center'), 'icon' => 'heroicon-o-paper-airplane'],
            'cutover' => ['label' => __('nav.cutover'), 'icon' => 'heroicon-o-rocket-launch'],
            'client-repository' => ['label' => __('nav.client_repository'), 'icon' => 'heroicon-o-folder-open'],
            'copilot' => ['label' => __('nav.ai_copilot'), 'icon' => 'heroicon-o-sparkles'],
        ],
    ];
    $activePage = last(explode('.', request()->route()?->getName() ?? ''));
    $activePage = match ($activePage) {
        'records' => 'database',
        'function-editor', 'function-tester' => 'functions',
        default => $activePage,
    };
    $pages = \App\Filament\Resources\Projects\ProjectResource::getPages();
    $groupOf = [];
    foreach ($groups as $group => $links) {
        foreach ($links as $page => $link) {
            $groupOf[$page] = $group;
        }
    }
    $openGroup = $groupOf[$activePage] ?? null;
@endphp
<nav class="cp-workspace" data-cp-project-sidebar aria-label="Project workspace">
    <a class="cp-workspace__back" href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('index') }}">← All Projects</a>
    {{-- Merged project switcher: identity + menu in one control (Phase 20.7). --}}
    @php $dotClass = $project->health_status === 'healthy' ? 'is-healthy' : ($project->health_status === 'unhealthy' ? 'is-danger' : 'is-unknown'); @endphp
    @if (\App\Filament\Pages\ProjectSwitcher::canAccess())
        <details class="cp-switcher">
            <summary class="cp-switcher__current" title="Active project — open to switch" aria-label="Active project {{ $project->name }}. Open to switch project.">
                <span class="cp-dot {{ $dotClass }}"></span>
                <div class="cp-workspace__meta">
                    <span class="cp-workspace__name" title="{{ $project->name }}">{{ $project->name }}</span>
                    <span class="cp-workspace__sub">{{ strtoupper($project->environment ?? 'local') }} · {{ ucfirst($project->health_status ?? 'unknown') }}</span>
                </div>
                <span class="cp-switcher__chev" aria-hidden="true">›</span>
            </summary>
            <div class="cp-switcher__menu">
                <input type="search" class="cp-switcher__search" placeholder="Search projects…" aria-label="Search projects"
                    oninput="for (const a of this.parentElement.querySelectorAll('a[data-name]')) { a.style.display = a.dataset.name.includes(this.value.toLowerCase()) ? '' : 'none'; }">
                @foreach (\App\Models\Project::query()->orderBy('name')->get(['id', 'name', 'environment']) as $workspace)
                    <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $workspace]) }}"
                        data-name="{{ mb_strtolower($workspace->name) }}" @if ($workspace->is($project)) aria-current="true" @endif>
                        <span>{{ $workspace->name }}</span>
                        <span class="cp-switcher__env">{{ strtoupper($workspace->environment ?? 'local') }}</span>
                    </a>
                @endforeach
            </div>
        </details>
    @else
        <div class="cp-workspace__head">
            <span class="cp-dot {{ $dotClass }}"></span>
            <div class="cp-workspace__meta">
                <span class="cp-workspace__name" title="{{ $project->name }}">{{ $project->name }}</span>
                <span class="cp-workspace__sub">{{ strtoupper($project->environment ?? 'local') }} · {{ ucfirst($project->health_status ?? 'unknown') }}</span>
            </div>
        </div>
    @endif
    {{-- Phase 24C environment switcher: updates all environment-scoped modules. --}}
    @php
        $envs = $project->environments()->orderBy('id')->get();
        $activeEnv = \App\Services\ControlPlane\EnvironmentContext::active($project);
    @endphp
    @if ($envs->count() > 0)
        <details class="cp-switcher cp-switcher--env">
            <summary class="cp-switcher__current" title="Active environment — open to switch" aria-label="Active environment {{ $activeEnv->name }}">
                <span class="cp-dot {{ $activeEnv->type === 'production' ? 'is-danger' : ($activeEnv->type === 'staging' ? 'is-warn' : 'is-healthy') }}"></span>
                <div class="cp-workspace__meta">
                    <span class="cp-workspace__name">{{ $activeEnv->name }}</span>
                    <span class="cp-workspace__sub">Environment</span>
                </div>
                <span class="cp-switcher__chev" aria-hidden="true">›</span>
            </summary>
            <div class="cp-switcher__menu">
                @foreach ($envs as $envOption)
                    <a href="{{ route('control-plane.environment-switch', ['project' => $project, 'environment' => $envOption]) }}"
                       @if ($envOption->is($activeEnv)) aria-current="true" @endif
                       @if ($envOption->status !== 'active') style="opacity:.5" @endif>
                        <span>{{ $envOption->name }}{{ $envOption->status !== 'active' ? ' (inactive)' : '' }}</span>
                        <span class="cp-switcher__env">{{ strtoupper($envOption->type) }}</span>
                    </a>
                @endforeach
                <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('environments', ['record' => $project]) }}"><span>Manage environments…</span></a>
            </div>
        </details>
    @endif
    <div class="cp-workspace__nav">
        @isset($pages['overview'])
            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('overview', ['record' => $project]) }}" @if ('overview' === $activePage) aria-current="page" @endif>
                <x-filament::icon icon="heroicon-o-home" />
                <span>Overview</span>
            </a>
        @endisset
        @foreach ($groups as $group => $links)
            @php $visible = array_filter(array_keys($links), fn ($page) => isset($pages[$page])); @endphp
            @continue($visible === [])
            <details class="cp-workspace__details" @if ($group === $openGroup) open @endif>
                <summary class="cp-workspace__group">{{ $group }}</summary>
                @foreach ($links as $page => $link)
                    @continue(! isset($pages[$page]))
                    <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl($page, ['record' => $project]) }}" @if ($page === $activePage) aria-current="page" @endif>
                        <x-filament::icon icon="{{ $link['icon'] }}" />
                        <span>{{ $link['label'] }}</span>
                    </a>
                @endforeach
            </details>
        @endforeach
        @isset($pages['settings'])
            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('settings', ['record' => $project]) }}" @if ('settings' === $activePage) aria-current="page" @endif>
                <x-filament::icon icon="heroicon-o-cog-6-tooth" />
                <span>Settings</span>
            </a>
        @endisset
    </div>
    <div class="cp-workspace__foot">
        <a href="/admin/team">Team</a>
        <a href="/admin/audit-logs">Audit Log</a>
    </div>
</nav>
