<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use App\Services\Access\Capability;
use App\Services\Product\ConnectorCatalogView;
use App\Services\Product\UiPreferenceService;
use BackedEnum;
use Filament\Pages\Page;

/**
 * 0.4.0 Phase F (§11) — the Connector Catalog as a real product catalogue.
 *
 * v0.3.0 (UX audit finding P9) rendered this as six full-width unstyled boxes
 * of debug text with no logos, descriptions, capability information, per-card
 * action, or working filters. It is now a catalogue:
 *
 *   icon · provider name · short description · capabilities ·
 *   migration support · Live Sync support · trust · status · version · CTA
 *
 * plus search and capability/trust filters.
 *
 * ACCESS
 * Entry needs `connectors.view`, which a workspace or project role may hold —
 * the previous gate required the legacy `projects.view` permission and so
 * excluded roles that should legitimately browse connectors.
 *
 * The page is read-only: it lists what is installed and links into the project
 * where a connection is actually made. It never connects to anything itself.
 */
class ConnectorCatalog extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationLabel = 'Connectors';

    protected static ?string $title = 'Connectors';

    protected static ?string $slug = 'connectors';

    protected string $view = 'filament.pages.connector-catalog';

    /** Live search box. */
    public string $search = '';

    /** @var list<string> capability keys that must all be present */
    public array $featureFilters = [];

    /** @var list<string> trust levels to include */
    public array $trustFilters = [];

    /** Show only connectors that are enabled. */
    public bool $onlyReady = false;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403, 'Missing capability: '.Capability::CONNECTORS_VIEW);
    }

    public static function canAccess(): bool
    {
        return PlatformAccess::current()->allowsPlatform(Capability::CONNECTORS_VIEW);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Everything you can migrate from, and what each one supports.';
    }

    // ── Data ───────────────────────────────────────────────────────────

    /**
     * Phase I — this user's appearance preference for the catalogue grid.
     *
     * @return array<string, array<string, mixed>>
     */
    public function uiPreferences(): array
    {
        return UiPreferenceService::for(auth()->user())->effectiveForComponents(['connectors.grid']);
    }

    /** @return list<array<string, mixed>> */
    public function allCards(): array
    {
        return ConnectorCatalogView::cards();
    }

    /** @return list<array<string, mixed>> */
    public function visibleCards(): array
    {
        return ConnectorCatalogView::filter(
            $this->allCards(),
            $this->search,
            $this->featureFilters,
            $this->trustFilters,
            $this->onlyReady,
        );
    }

    /** @return list<array{key: string, label: string, group: string, count: int}> */
    public function featureOptions(): array
    {
        return ConnectorCatalogView::availableFeatures($this->allCards());
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public function trustOptions(): array
    {
        return ConnectorCatalogView::availableTrusts($this->allCards());
    }

    public function totalCount(): int
    {
        return count($this->allCards());
    }

    public function hasActiveFilters(): bool
    {
        return $this->search !== '' || $this->featureFilters !== [] || $this->trustFilters !== [] || $this->onlyReady;
    }

    /**
     * Where "Connect" should go.
     *
     * A connector is connected INSIDE a project, so the catalogue routes there
     * rather than pretending it can connect from a platform-level page. With
     * exactly one reachable project we can go straight to its Connect screen;
     * otherwise the projects list is the honest destination.
     *
     * Returns null when the user has no project to connect into, so the view can
     * say so instead of rendering a dead link.
     */
    public function connectUrl(): ?string
    {
        $projects = PlatformAccess::current()->access()->accessibleProjects();

        try {
            if ($projects->count() === 1) {
                return ProjectResource::getUrl('connect', ['record' => $projects->first()]);
            }

            return ProjectResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }

    /** True when the user has a project to connect into. */
    public function hasConnectTarget(): bool
    {
        return PlatformAccess::current()->access()->accessibleProjects()->isNotEmpty();
    }

    // ── Filter actions (Livewire) ──────────────────────────────────────

    public function toggleFeature(string $key): void
    {
        $this->featureFilters = $this->toggle($this->featureFilters, $key);
    }

    public function toggleTrust(string $key): void
    {
        $this->trustFilters = $this->toggle($this->trustFilters, $key);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->featureFilters = [];
        $this->trustFilters = [];
        $this->onlyReady = false;
    }

    /** @param list<string> $list @return list<string> */
    private function toggle(array $list, string $key): array
    {
        return in_array($key, $list, true)
            ? array_values(array_diff($list, [$key]))
            : array_values([...$list, $key]);
    }
}
