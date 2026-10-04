<?php

namespace App\Filament\Support;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DeveloperAgent;
use App\Filament\Pages\SettingsHub;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\Access\Capability;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;

/**
 * 0.6.0 Phase B — the PLATFORM-scope navigation (audit §B1).
 *
 * The sidebar is a MAP, not a database. Five product destinations plus one
 * compact AI experience group; everything else moved to where it belongs:
 *
 *   Home · Projects · Clients · Activity · AI [Nexus AI · Developer Agent]
 *   · Settings (a hub that organizes Members / AI / Connectors / System)
 *
 * MOVED IN 0.6.0 (from the 0.4.0 sidebar):
 *   Operations (project-scoped Queues/Logs/Monitoring/Scheduler/Webhooks/
 *     Realtime) — DELETED from the platform sidebar: these are project
 *     destinations and already live in the project tab bar (Operate).
 *   Infrastructure (Nodes/Services/Health/Topology) → Settings ▸ System.
 *   Connectors → Settings ▸ Connectors (and surfaced in the New Project flow).
 *   Search → the topbar search + the "/" shortcut (route kept for compat).
 *   Get started (Onboarding) → surfaced contextually from Home; out of the
 *     permanent sidebar.
 *   AI configuration (Nexus AI Settings / Developer Agent Settings) →
 *     Settings ▸ AI.
 *
 * RULES ENFORCED HERE
 *  - ≤ 8 primary destinations per scope.
 *  - No group with a single child — a one-item group is just an item.
 *  - Items are filtered by CAPABILITY, never by a role name.
 *  - Hidden navigation is NOT a security boundary: every destination re-checks
 *    its own capability server-side. This method only decides what is worth
 *    showing.
 */
class ProductNavigation
{
    /** Top-level entries, in order. Asserted by the 0.6.0 navigation test. */
    public const PLATFORM_ENTRIES = [
        'Home',
        'Projects',
        'Clients',
        'Activity',
        'AI',
        'Settings',
    ];

    public static function build(): NavigationBuilder
    {
        $builder = new NavigationBuilder;
        $access = PlatformAccess::current();
        $project = ControlPlaneChrome::currentProject();

        // ── Ungrouped primary entries ──────────────────────────────────

        $builder->item(
            NavigationItem::make(__('nav.home'))
                ->icon('heroicon-o-home')
                ->url(fn (): string => Dashboard::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.dashboard'))
        );

        $builder->item(
            NavigationItem::make(__('nav.projects'))
                ->icon('heroicon-o-square-3-stack-3d')
                ->url(fn (): string => ProjectResource::getUrl('index'))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.projects.*')
                    && ! request()->routeIs('filament.admin.resources.projects.copilot'))
        );

        if ($access->allowsPlatform(Capability::WORKSPACES_VIEW)) {
            $builder->item(
                NavigationItem::make(__('nav.clients'))
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (): string => \App\Filament\Pages\Workspaces::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.workspaces*'))
            );
        }

        if ($access->allowsPlatform(Capability::AUDIT_VIEW)) {
            $builder->item(
                NavigationItem::make(__('nav.activity'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (): string => AuditLogResource::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.audit-logs.*'))
            );
        }

        // ── AI — the assistant and the coding agent are one experience.
        // Configuration for both lives in Settings ▸ AI; this group is the
        // day-to-day surface, not a settings path.
        $aiGroup = [];
        if ($access->allowsPlatform(Capability::AI_USE)) {
            $aiGroup[] = NavigationItem::make(__('nav.nexus_ai'))
                ->icon('heroicon-o-sparkles')
                ->url(fn (): string => NexusAi::urlFor($project))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.nexus-ai')
                    || request()->routeIs('filament.admin.resources.projects.copilot'));
        }
        if ($access->allowsPlatform(Capability::AGENTS_VIEW)) {
            $aiGroup[] = NavigationItem::make(__('nav.developer_agent'))
                ->icon('heroicon-o-command-line')
                ->url(fn (): string => DeveloperAgent::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.developer-agent'));
        }

        if (count($aiGroup) === 1) {
            // No single-child groups (IA rule): a lone item stays ungrouped.
            $builder->item($aiGroup[0]);
        } elseif (count($aiGroup) > 1) {
            $builder->group(__('nav.ai_group'), $aiGroup);
        }

        // ── Settings — one destination that organizes configuration
        // (Members · AI · Connectors · System). Filtered by capability
        // inside the hub; shown when the user can reach ANY section.
        if ($access->allowsPlatform(Capability::TEAM_VIEW)
            || $access->allowsPlatform(Capability::AI_CONFIGURE)
            || $access->allowsPlatform(Capability::AGENTS_CONFIGURE)
            || $access->allowsPlatform(Capability::CONNECTORS_VIEW)
            || $access->allowsPlatform(Capability::INFRASTRUCTURE_VIEW)
        ) {
            $builder->item(
                NavigationItem::make(__('nav.settings'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (): string => SettingsHub::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.pages.settings-hub')
                        || request()->routeIs('filament.admin.pages.nexus-ai-settings')
                        || request()->routeIs('filament.admin.pages.developer-agent-settings')
                        || request()->routeIs('filament.admin.pages.team')
                        || request()->routeIs('filament.admin.pages.connector-catalog')
                        || request()->routeIs('filament.admin.pages.infra-*')
                        || request()->routeIs('filament.admin.pages.onboarding')
                        || request()->routeIs('filament.admin.pages.search'))
            );
        }

        return $builder;
    }
}
