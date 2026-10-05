<?php

namespace App\Filament\Support;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;

/**
 * 0.6.0 Phase B — the project context navigation model (audit §B4/§B5).
 *
 * A project is ONE product area with contextual TAB navigation, not 35
 * sidebar links appended to the platform shell. Primary tabs are the
 * product map (≤ 8); everything else is a secondary destination inside
 * its tab.
 *
 * Visibility is capability-aware and deterministic: every secondary item
 * asks its page class `canAccess()` (the same server-side check the page
 * itself enforces), so a viewer never sees destinations they cannot open.
 * Primary tabs appear when at least one of their pages is accessible.
 *
 * Underlying pages keep their routes — this only re-expresses the map.
 */
class ProjectTabs
{
    /**
     * Primary tab => [translation key, pages (slug => translation key)].
     * ORDER IS THE PRODUCT HIERARCHY: overview first, settings last.
     */
    public const TABS = [
        'overview' => [
            'label' => 'nav.overview',
            'pages' => [
                'overview' => 'nav.overview',
            ],
        ],
        'migration' => [
            'label' => 'nav.tab_migration',
            'pages' => [
                // 0.6.0 Phase E — the journey IS the tab: six stage tabs on one
                // page (Connect/Analyze/Plan/Sync/Verify/Cutover), absorbing
                // the Migration Center / Cutover / Readiness / Copilot faces.
                // Those pages keep their routes as deep links; only the tools
                // below remain secondary destinations.
                'migration' => 'nav.migration',
                'migrations' => 'nav.migrations',
                'schema-diff' => 'nav.schema_diff',
            ],
        ],
        'data' => [
            'label' => 'nav.tab_data',
            'pages' => [
                'database' => 'nav.tables',
                'table-schema' => 'nav.schema',
                'erd' => 'nav.erd',
                'sql' => 'nav.sql_editor',
                'db-functions' => 'nav.functions',
                'db-advanced' => 'nav.inspector',
                'db-health' => 'nav.db_health',
                'connections' => 'nav.connections',
            ],
        ],
        'access' => [
            'label' => 'nav.tab_access',
            'pages' => [
                'users' => 'nav.users',
                'roles' => 'nav.roles',
                'permissions' => 'nav.permissions',
                'sessions' => 'nav.sessions',
                'auth-security' => 'nav.providers',
                'secrets' => 'nav.secrets',
            ],
        ],
        'build' => [
            'label' => 'nav.group_build',
            'pages' => [
                'api' => 'nav.api',
                'keys' => 'nav.api_keys',
                'storage' => 'nav.storage',
                'functions' => 'nav.functions',
                'realtime' => 'nav.realtime',
                'webhooks' => 'nav.webhooks',
                'scheduler' => 'nav.scheduler',
                'connect' => 'nav.connect',
            ],
        ],
        'operate' => [
            'label' => 'nav.group_operate',
            'pages' => [
                'logs' => 'nav.logs',
                'queues' => 'nav.queues',
                'monitoring' => 'nav.monitoring',
                'backups' => 'nav.backups',
                'environments' => 'nav.environments',
                'readiness' => 'nav.readiness',
                'infrastructure' => 'nav.infrastructure',
                'resources' => 'nav.resources',
            ],
        ],
        'settings' => [
            'label' => 'nav.settings',
            'pages' => [
                'settings' => 'nav.settings',
            ],
        ],
    ];

    /** Route-name last segments that are secondary views of another page. */
    private const PAGE_ALIASES = [
        'records' => 'database',
        'function-editor' => 'functions',
        'function-tester' => 'functions',
        // 0.6.0 Phase E (§E13) — absorbed into the Migration journey: the
        // pages keep their routes as deep links and resolve to the Migration
        // tab, whose journey page now presents their content as stages.
        'migration-center' => 'migration',
        'cutover' => 'migration',
        'copilot' => 'migration',
        'client-repository' => 'migration',
    ];

    /**
     * Which primary tab a page slug belongs to.
     *
     * @param  string  $slug  Route-name last segment, e.g. `cutover`, `records`.
     */
    public static function tabOf(string $slug): ?string
    {
        $slug = self::PAGE_ALIASES[$slug] ?? $slug;

        foreach (self::TABS as $tab => $definition) {
            if (array_key_exists($slug, $definition['pages'])) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Resolve the tab bar for a project: visible primary tabs and the
     * active tab's visible secondary items, filtered by the page classes'
     * own `canAccess()` capability checks.
     *
     * @param  Project  $project
     * @param  string|null  $activeSlug  Route-name last segment.
     * @return array{tabs: array<int, array{key: string, label: string, url: string, active: bool}>, secondary: array<int, array{key: string, label: string, url: string, active: bool}>, activeTab: ?string}
     */
    public static function resolve(Project $project, ?string $activeSlug): array
    {
        $activeSlug = self::PAGE_ALIASES[$activeSlug ?? ''] ?? $activeSlug;
        $pages = ProjectResource::getPages();

        $tabs = [];
        $secondary = [];
        $activeTab = $activeSlug !== null ? self::tabOf($activeSlug) : null;

        foreach (self::TABS as $key => $definition) {
            $items = [];

            foreach ($definition['pages'] as $slug => $labelKey) {
                $registration = $pages[$slug] ?? null;
                if ($registration === null) {
                    continue; // page not registered in this build
                }

                $pageClass = $registration->getPage();

                if (! $pageClass::canAccess(['record' => $project->getKey()])) {
                    continue;
                }

                $items[] = [
                    'key' => $slug,
                    'label' => __($labelKey),
                    'url' => ProjectResource::getUrl($slug, ['record' => $project]),
                    'active' => $slug === $activeSlug,
                ];
            }

            if ($items === []) {
                continue;
            }

            $primary = [
                'key' => $key,
                'label' => __($definition['label']),
                'url' => $items[0]['url'],
                'active' => $key === $activeTab,
            ];

            $tabs[] = $primary;

            if ($key === $activeTab) {
                $secondary = $items;
            }
        }

        return [
            'tabs' => $tabs,
            'secondary' => $secondary,
            'activeTab' => $activeTab,
        ];
    }

    /** All known primary tab keys, in product order (for tests). */
    public static function primaryKeys(): array
    {
        return array_keys(self::TABS);
    }
}
