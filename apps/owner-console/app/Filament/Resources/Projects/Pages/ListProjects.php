<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Pages\NewProjectWizard;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use App\Models\Workspace;
use App\Services\Product\JourneyState;
use App\Services\Product\PlatformPulse;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * 0.6.0 Phase D (§D1) — PROJECTS INDEX as a product view.
 *
 * The old admin table (slug / status / health / api_domain / db_name /
 * deploy_status columns) is GONE from the default face. A project is now a
 * decision: name, client, environment, journey stage, state, progress, and
 * one obvious action — nothing else. Technical identifiers live in project
 * settings and the overview's technical-details disclosure.
 *
 * Ordering is THE canonical attention-first order from `PlatformPulse::indexRows()`
 * (attention → in progress → recently meaningful → others); no second
 * health interpretation is derived here. Data is batched (§D13): a fixed
 * ~dozen queries regardless of project count, then filtered/sliced in
 * memory. Filters are simple and few — no filter per database column.
 *
 * The page keeps the `index` route of the resource, so every existing link
 * keeps working; the technical table itself remains only inside Filament's
 * edit surfaces, never as the projects destination.
 */
class ListProjects extends Page
{
    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    /** Rows per page (§D13 — pagination over the ordered rows). */
    protected int $perPage = 24;

    #[Url(as: 'filter')]
    public string $filter = 'all';

    #[Url(as: 'env')]
    public string $filterEnvironment = '';

    #[Url(as: 'ws')]
    public string $filterWorkspace = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'view')]
    public string $mode = 'cards';

    /**
     * Pagination cursor (query param `paged` — `page` is reserved by
     * Livewire's own pagination machinery on Filament surfaces).
     */
    #[Url(as: 'paged')]
    public int $pageNumber = 1;

    public function mount(): void
    {
        $this->filter = in_array($this->filter, ['all', 'attention', 'in_progress', 'completed'], true)
            ? $this->filter
            : 'all';
        $this->mode = in_array($this->mode, ['cards', 'compact'], true) ? $this->mode : 'cards';
        $this->pageNumber = max(1, $this->pageNumber);
    }

    /** §D1: one obvious way out of a no-matches state. */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
        $this->filterEnvironment = '';
        $this->filterWorkspace = '';
        $this->pageNumber = 1;
    }

    public function getTitle(): string|Htmlable
    {
        return __('nav.projects');
    }

    public function getBreadcrumbs(): array
    {
        return [__('nav.projects')];
    }

    protected function getHeaderActions(): array
    {
        return [
            // §D1: ONE primary creation action — the guided wizard. The legacy
            // form remains only as a redirect, never as a destination.
            Action::make('new_project')
                ->label(__('wizard.title'))
                ->icon('heroicon-o-plus')
                ->visible(fn () => PlatformAccess::current()->canCreateProject())
                ->url(fn () => NewProjectWizard::getUrl()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'nx-width-shell'])->components([
            Html::make(fn (): string => view('filament.pages.projects', $this->projectsViewData())->render()),
        ]);
    }

    /**
     * Everything the index view needs, resolved in PHP (the view is rendered
     * through `view()`, so page helpers are not in scope inside it).
     *
     * @return array<string, mixed>
     */
    protected function projectsViewData(): array
    {
        $pulse = PlatformPulse::for(PlatformAccess::current()->access());

        // A failing pulse degrades to an empty page with the error pattern,
        // never a 500 (§ERRORS / §D11).
        try {
            $rows = $pulse->indexRows();
            $error = null;
        } catch (\Throwable $e) {
            report($e);
            $rows = collect();
            $error = $e;
        }

        $total = $rows->count();
        $attentionCount = $rows->filter(fn (array $row): bool => in_array($row['state'], [JourneyState::BLOCKED, JourneyState::NEEDS_ATTENTION], true))->count();
        $inProgressCount = $rows->filter(fn (array $row): bool => in_array($row['state'], [JourneyState::IN_PROGRESS, JourneyState::READY], true))->count();
        $completedCount = $rows->filter(fn (array $row): bool => $row['state'] === JourneyState::COMPLETE)->count();

        $rows = $this->applyFilters($rows);

        // Workspaces the user can actually see, for the workspace filter.
        try {
            $workspaces = Workspace::query()
                ->whereIn('id', PlatformAccess::current()->workspaceIds())
                ->orderBy('name')
                ->get(['id', 'name']);
        } catch (\Throwable) {
            $workspaces = collect();
        }

        $lastPage = max(1, (int) ceil($rows->count() / $this->perPage));
        $this->pageNumber = min(max(1, $this->pageNumber), $lastPage);

        return [
            'rows' => $rows->slice(($this->pageNumber - 1) * $this->perPage, $this->perPage)->values(),
            'total' => $total,
            'counts' => [
                'all' => $total,
                'attention' => $attentionCount,
                'in_progress' => $inProgressCount,
                'completed' => $completedCount,
            ],
            'workspaces' => $workspaces,
            'canCreate' => PlatformAccess::current()->canCreateProject(),
            'page' => $this->pageNumber,
            'lastPage' => $lastPage,
            'error' => $error,
            // The view is rendered through view() outside the Livewire scope,
            // so the interactive state is passed explicitly.
            'filter' => $this->filter,
            'mode' => $this->mode,
            'search' => $this->search,
            'filterEnvironment' => $this->filterEnvironment,
            'filterWorkspace' => $this->filterWorkspace,
            'openUrl' => fn ($project): ?string => $this->projectUrl($project),
        ];
    }

    /** §D1 filters: a handful of useful ones, never one per column. */
    protected function applyFilters($rows)
    {
        if ($this->search !== '') {
            $needle = mb_strtolower(trim($this->search));
            $rows = $rows->filter(function (array $row) use ($needle): bool {
                $haystacks = [
                    mb_strtolower((string) $row['project']->name),
                    mb_strtolower((string) $row['workspace']),
                    // The slug is a safe human identifier (it names the project's
                    // own URLs) — searchable, but never displayed (§D1).
                    mb_strtolower((string) $row['project']->slug),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, $needle)) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        if ($this->filterEnvironment !== '') {
            $rows = $rows->filter(
                fn (array $row): bool => ($row['project']->environment ?? 'local') === $this->filterEnvironment,
            )->values();
        }

        if ($this->filterWorkspace !== '') {
            $rows = $rows->filter(
                fn (array $row): bool => (string) $row['project']->workspace_id === (string) $this->filterWorkspace,
            )->values();
        }

        return match ($this->filter) {
            'attention' => $rows->filter(fn (array $row): bool => in_array($row['state'], [JourneyState::BLOCKED, JourneyState::NEEDS_ATTENTION], true))->values(),
            'in_progress' => $rows->filter(fn (array $row): bool => in_array($row['state'], [JourneyState::IN_PROGRESS, JourneyState::READY], true))->values(),
            'completed' => $rows->filter(fn (array $row): bool => $row['state'] === JourneyState::COMPLETE)->values(),
            default => $rows,
        };
    }

    private function projectUrl($project): ?string
    {
        try {
            return ProjectResource::getUrl('overview', ['record' => $project]);
        } catch (\Throwable) {
            return null;
        }
    }
}
