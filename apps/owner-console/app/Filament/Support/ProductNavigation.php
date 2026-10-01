<?php

namespace App\Filament\Support;

use App\Filament\Pages\ConnectorCatalog;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\GlobalSearch;
use App\Filament\Pages\InfraHealth;
use App\Filament\Pages\InfraNodes;
use App\Filament\Pages\InfraServices;
use App\Filament\Pages\InfraTopology;
use App\Filament\Pages\OnboardingWizard;
use App\Filament\Pages\TeamManagement;
use App\Filament\Pages\Workspaces;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\Access\Capability;
use App\Services\Access\Roles;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;

/**
 * 0.4.0 Phase C — the PLATFORM-scope global navigation.
 *
 * Replaces v0.3.0's five groups, one of which ("Migration center") held exactly
 * one link. The target is a short list where every entry answers a question a
 * real operator asks. See docs/product/INFORMATION_ARCHITECTURE.md §3.
 *
 *   Home · Projects · Clients & Workspaces · Connectors · Nexus AI
 *        · Operations · Infrastructure · Security · Settings
 *
 * RULES ENFORCED HERE
 *  - At most nine top-level entries.
 *  - No group with a single child — a one-item group is just an item.
 *  - Items are filtered by CAPABILITY, never by a role name.
 *  - Hidden navigation is NOT a security boundary: every destination re-checks
 *    its own capability server-side. This method only decides what is worth
 *    showing.
 */
class ProductNavigation
{
    /** Top-level entries, in order. Asserted by ProductNavigationTest. */
    public const PLATFORM_ENTRIES = [
        'Home',
        'Projects',
        'Clients & Workspaces',
        'Connectors',
        'Nexus AI',
        'Operations',
        'Infrastructure',
        'Security',
        'Settings',
    ];

    public static function build(): NavigationBuilder
    {
        $builder = new NavigationBuilder;
        $access = PlatformAccess::current();
        $project = ControlPlaneChrome::currentProject();

        // ── Ungrouped primary entries ──────────────────────────────────

        $builder->item(
            NavigationItem::make('Home')
                ->icon('heroicon-o-home')
                ->url(fn (): string => Dashboard::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.dashboard'))
        );

        $builder->item(
            NavigationItem::make('Projects')
                ->icon('heroicon-o-square-3-stack-3d')
                ->url(fn (): string => ProjectResource::getUrl('index'))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.projects.index')
                    || request()->routeIs('filament.admin.resources.projects.create'))
        );

        if ($access->allowsPlatform(Capability::WORKSPACES_VIEW)) {
            $builder->item(
                NavigationItem::make('Clients & Workspaces')
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (): string => Workspaces::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.workspaces*'))
            );
        }

        if ($access->allowsPlatform(Capability::CONNECTORS_VIEW)) {
            $builder->item(
                NavigationItem::make('Connectors')
                    ->icon('heroicon-o-puzzle-piece')
                    ->url(fn (): string => ConnectorCatalog::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.connector-catalog'))
            );
        }

        if ($access->allowsPlatform(Capability::AI_USE)) {
            $builder->item(
                NavigationItem::make('Nexus AI')
                    ->icon('heroicon-o-sparkles')
                    ->url(fn (): string => NexusAi::urlFor($project))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.nexus-ai')
                        || request()->routeIs('filament.admin.resources.projects.copilot'))
            );
        }

        // ── Operations ─────────────────────────────────────────────────
        // v0.3.0 scattered these across a project-only "Operate" group and had
        // no platform-level view at all. One entry; contents follow the active
        // scope. Nothing is shown when no project is in context, because a
        // queues page with no project would be meaningless.
        $operations = [];
        if ($project) {
            foreach ([
                ['Queues', 'heroicon-o-queue-list', 'queues'],
                ['Logs', 'heroicon-o-document-text', 'logs'],
                ['Monitoring', 'heroicon-o-chart-bar', 'monitoring'],
                ['Scheduler', 'heroicon-o-calendar-days', 'scheduler'],
                ['Webhooks', 'heroicon-o-link', 'webhooks'],
                ['Realtime', 'heroicon-o-signal', 'realtime'],
            ] as [$label, $icon, $page]) {
                $operations[] = NavigationItem::make($label)
                    ->icon($icon)
                    ->url(fn (): string => ProjectResource::getUrl($page, ['record' => $project]))
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.projects.'.$page));
            }
        }
        if ($operations !== []) {
            $builder->group('Operations', $operations);
        }

        // ── Infrastructure ─────────────────────────────────────────────
        if ($access->allowsPlatform(Capability::INFRASTRUCTURE_VIEW)) {
            $builder->group('Infrastructure', [
                NavigationItem::make('Nodes')
                    ->icon('heroicon-o-server-stack')
                    ->url(fn (): string => InfraNodes::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.infra-nodes')),
                NavigationItem::make('Services')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->url(fn (): string => InfraServices::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.infra-services')),
                NavigationItem::make('Health')
                    ->icon('heroicon-o-heart')
                    ->url(fn (): string => InfraHealth::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.infra-health')),
                NavigationItem::make('Topology')
                    ->icon('heroicon-o-share')
                    ->url(fn (): string => InfraTopology::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.infra-topology')),
            ]);
        }

        // ── Security ───────────────────────────────────────────────────
        // v0.3.0 called this "Governance" — internal-audit vocabulary.
        $security = [];
        if ($access->allowsPlatform(Capability::TEAM_VIEW)) {
            $security[] = NavigationItem::make('Members')
                ->icon('heroicon-o-user-group')
                ->url(fn (): string => TeamManagement::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.team'));
        }
        if ($access->allowsPlatform(Capability::AUDIT_VIEW)) {
            $security[] = NavigationItem::make('Audit log')
                ->icon('heroicon-o-clipboard-document-list')
                ->url(fn (): string => AuditLogResource::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.audit-logs.*'));
        }
        if ($security !== []) {
            $builder->group('Security', $security);
        }

        // ── Settings ───────────────────────────────────────────────────
        $builder->group('Settings', [
            NavigationItem::make('Get started')
                ->icon('heroicon-o-academic-cap')
                ->url(fn (): string => OnboardingWizard::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.onboarding')),
            NavigationItem::make('Search')
                ->icon('heroicon-o-magnifying-glass')
                ->url(fn (): string => GlobalSearch::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.search')),
        ]);

        return $builder;
    }

    /** Human labels for the role picker; never used to authorise. */
    public static function roleLabels(string $scope): array
    {
        $roles = match ($scope) {
            'platform' => Roles::platformRoles(),
            'workspace' => Roles::workspaceRoles(),
            'project' => Roles::projectRoles(),
            default => [],
        };

        $out = [];
        foreach ($roles as $role) {
            $out[$role] = Roles::label($role);
        }

        return $out;
    }
}
