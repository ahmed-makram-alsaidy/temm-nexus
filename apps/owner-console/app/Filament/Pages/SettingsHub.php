<?php

namespace App\Filament\Pages;

use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use Filament\Pages\Page;

/**
 * 0.6.0 Phase B — the Settings destination (audit §B2).
 *
 * Settings is ONE product destination that organizes configuration:
 *
 *   Members (access administration)
 *   AI (Assistant · Developer Agents)
 *   Connectors
 *   System (Infrastructure · Services · Health · Topology)
 *
 * Every sub-page keeps its own route and capability check — the hub is the
 * map, not a gate. Sections appear only when this user's capabilities allow
 * the underlying destination (permission-aware navigation density, §B13).
 *
 * There is deliberately no "General" section yet: platform identity is
 * configured once in the setup wizard, and no other platform-wide general
 * settings page exists in 0.5.0. The hub grows one when the product does.
 */
class SettingsHub extends Page
{
    protected string $view = 'filament.pages.settings-hub';

    /** The canonical Settings destination: /admin/settings. */
    protected static ?string $slug = 'settings';

    public function getTitle(): string
    {
        return __('settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('settings.subtitle');
    }

    public static function canAccess(array $parameters = []): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $access = PlatformAccess::current();

        return $access->allowsPlatform(Capability::TEAM_VIEW)
            || $access->allowsPlatform(Capability::AI_CONFIGURE)
            || $access->allowsPlatform(Capability::AGENTS_CONFIGURE)
            || $access->allowsPlatform(Capability::CONNECTORS_VIEW)
            || $access->allowsPlatform(Capability::INFRASTRUCTURE_VIEW);
    }

    /**
     * Static wrapper so tests (and future callers) can inspect the
     * capability-filtered section map without booting a Livewire page.
     *
     * @return array<int, array{key: string, title: string, description: string, icon: string, links: array<int, array{label: string, description: string, url: string}>}>
     */
    public static function hubSectionsForTesting(): array
    {
        return (new static)->sections();
    }

    /**
     * The capability-filtered sections the hub will render.
     *
     * @return array<int, array{key: string, title: string, description: string, icon: string, links: array<int, array{label: string, description: string, url: string}>}>
     */
    public function sections(): array
    {
        $access = PlatformAccess::current();
        $sections = [];

        if ($access->allowsPlatform(Capability::TEAM_VIEW)) {
            $sections[] = [
                'key' => 'members',
                'title' => __('settings.members_title'),
                'description' => __('settings.members_description'),
                'icon' => 'heroicon-o-user-group',
                'links' => [
                    [
                        'label' => __('settings.members_manage'),
                        'description' => __('settings.members_description'),
                        'url' => TeamManagement::getUrl(),
                    ],
                ],
            ];
        }

        $aiLinks = [];
        if ($access->allowsPlatform(Capability::AI_CONFIGURE)) {
            $aiLinks[] = [
                'label' => __('settings.ai_assistant'),
                'description' => __('settings.ai_assistant_description'),
                'url' => NexusAiSettings::getUrl(),
            ];
        }
        if ($access->allowsPlatform(Capability::AGENTS_CONFIGURE)) {
            $aiLinks[] = [
                'label' => __('settings.ai_agents'),
                'description' => __('settings.ai_agents_description'),
                'url' => DeveloperAgentSettings::getUrl(),
            ];
        }
        if ($aiLinks !== []) {
            $sections[] = [
                'key' => 'ai',
                'title' => __('settings.ai_title'),
                'description' => __('settings.ai_description'),
                'icon' => 'heroicon-o-sparkles',
                'links' => $aiLinks,
            ];
        }

        if ($access->allowsPlatform(Capability::CONNECTORS_VIEW)) {
            $sections[] = [
                'key' => 'connectors',
                'title' => __('settings.connectors_title'),
                'description' => __('settings.connectors_description'),
                'icon' => 'heroicon-o-puzzle-piece',
                'links' => [
                    [
                        'label' => __('settings.connectors_browse'),
                        'description' => __('settings.connectors_description'),
                        'url' => ConnectorCatalog::getUrl(),
                    ],
                ],
            ];
        }

        $systemLinks = [];
        if ($access->allowsPlatform(Capability::INFRASTRUCTURE_VIEW)) {
            $systemLinks = [
                [
                    'label' => __('settings.system_nodes'),
                    'description' => __('settings.system_nodes_description'),
                    'url' => InfraNodes::getUrl(),
                ],
                [
                    'label' => __('settings.system_services'),
                    'description' => __('settings.system_services_description'),
                    'url' => InfraServices::getUrl(),
                ],
                [
                    'label' => __('settings.system_health'),
                    'description' => __('settings.system_health_description'),
                    'url' => InfraHealth::getUrl(),
                ],
                [
                    'label' => __('settings.system_topology'),
                    'description' => __('settings.system_topology_description'),
                    'url' => InfraTopology::getUrl(),
                ],
            ];

            $sections[] = [
                'key' => 'system',
                'title' => __('settings.system_title'),
                'description' => __('settings.system_description'),
                'icon' => 'heroicon-o-server-stack',
                'links' => $systemLinks,
            ];
        }

        return $sections;
    }
}
