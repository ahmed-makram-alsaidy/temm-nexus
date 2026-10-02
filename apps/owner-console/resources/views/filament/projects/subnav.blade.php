{{-- Project workspace sidebar. One primary navigation; no horizontal bar. --}}
@php
    $groups = [
        'Database' => [
            'database' => ['label' => 'Tables', 'icon' => 'heroicon-o-circle-stack'],
            'erd' => ['label' => 'ERD', 'icon' => 'heroicon-o-share'],
            'sql' => ['label' => 'SQL Editor', 'icon' => 'heroicon-o-command-line'],
            'table-schema' => ['label' => 'Schema', 'icon' => 'heroicon-o-document-duplicate'],
            'db-functions' => ['label' => 'Functions', 'icon' => 'heroicon-o-variable'],
            'db-advanced' => ['label' => 'Inspector', 'icon' => 'heroicon-o-adjustments-horizontal'],
            'migrations' => ['label' => 'Migrations', 'icon' => 'heroicon-o-arrows-right-left'],
            'schema-diff' => ['label' => 'Schema Diff', 'icon' => 'heroicon-o-scissors'],
            'db-health' => ['label' => 'Health', 'icon' => 'heroicon-o-heart'],
            'connections' => ['label' => 'Connections', 'icon' => 'heroicon-o-globe-alt'],
        ],
        'Authentication' => [
            'users' => ['label' => 'Users', 'icon' => 'heroicon-o-users'],
            'roles' => ['label' => 'Roles', 'icon' => 'heroicon-o-key'],
            'permissions' => ['label' => 'Permissions', 'icon' => 'heroicon-o-shield-check'],
            'sessions' => ['label' => 'Sessions', 'icon' => 'heroicon-o-computer-desktop'],
            'auth-security' => ['label' => 'Providers', 'icon' => 'heroicon-o-lock-closed'],
            'secrets' => ['label' => 'Secrets', 'icon' => 'heroicon-o-eye-slash'],
        ],
        'Build' => [
            'storage' => ['label' => 'Storage', 'icon' => 'heroicon-o-folder'],
            'api' => ['label' => 'API', 'icon' => 'heroicon-o-bolt'],
            'connect' => ['label' => 'Connect', 'icon' => 'heroicon-o-device-phone-mobile'],
            'keys' => ['label' => 'API Keys', 'icon' => 'heroicon-o-finger-print'],
            'functions' => ['label' => 'Functions', 'icon' => 'heroicon-o-code-bracket'],
            'realtime' => ['label' => 'Realtime', 'icon' => 'heroicon-o-signal'],
            'webhooks' => ['label' => 'Webhooks', 'icon' => 'heroicon-o-link'],
            'scheduler' => ['label' => 'Scheduler', 'icon' => 'heroicon-o-calendar-days'],
        ],
        'Operate' => [
            'logs' => ['label' => 'Logs', 'icon' => 'heroicon-o-document-text'],
            'queues' => ['label' => 'Queues', 'icon' => 'heroicon-o-queue-list'],
            'monitoring' => ['label' => 'Monitoring', 'icon' => 'heroicon-o-chart-bar'],
            'backups' => ['label' => 'Backups', 'icon' => 'heroicon-o-archive-box'],
            'infrastructure' => ['label' => 'Infrastructure', 'icon' => 'heroicon-o-server-stack'],
            'resources' => ['label' => 'Resources', 'icon' => 'heroicon-o-cpu-chip'],
            'readiness' => ['label' => 'Readiness', 'icon' => 'heroicon-o-clipboard-document-check'],
        ],
        'Project' => [
            'environments' => ['label' => 'Environments', 'icon' => 'heroicon-o-squares-2x2'],
            'migration-center' => ['label' => 'Migration Center', 'icon' => 'heroicon-o-paper-airplane'],
            'cutover' => ['label' => 'Cutover', 'icon' => 'heroicon-o-rocket-launch'],
            'client-repository' => ['label' => 'Client Repository', 'icon' => 'heroicon-o-folder-open'],
            'copilot' => ['label' => 'AI Copilot', 'icon' => 'heroicon-o-sparkles'],
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
