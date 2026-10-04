<?php

namespace App\Filament\Resources\Projects\Pages\Concerns;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Shared context for every project-scoped control-plane page.
 * The {record} route binding is the ONLY project selector: all data access
 * flows through it, so cross-project leakage requires forging the record id —
 * which resolves to that project's own connection/paths anyway.
 */
trait HasProjectContext
{
    public function project(): Project
    {
        /** @var Project $record */
        $record = $this->getRecord();
        abort_unless($record instanceof Project, 404);

        return $record;
    }

    public function explorer(): ProjectDatabaseExplorer
    {
        return ProjectDatabaseExplorer::for($this->project());
    }

    protected function audit(string $action, ?string $targetType = null, int|string|null $targetId = null, array $metadata = []): void
    {
        AdminAudit::record($action, $this->project(), $targetType, $targetId, $metadata);
    }

    /** Workspace navigation lives in the single project sidebar (Phase 20.5). */
    protected function subnav(string $active): Section
    {
        return Section::make()->hidden();
    }

    protected function subnavSection(string $active): Section
    {
        return $this->subnav($active);
    }

    /**
     * 0.4.0 — entry to a project page.
     *
     * This used to be `auth()->check() && auth()->user()->is_admin`, which gated
     * EVERY one of the ~45 project-scoped pages on the legacy global admin flag.
     * Once 0.4.0 introduced scoped roles that had two consequences:
     *
     *   1. A user granted a workspace or project role — the entire point of the
     *      Workspace layer — could not open any project page at all.
     *   2. It sat *above* every per-page capability check, so the capability
     *      model underneath it was unreachable for non-admins.
     *
     * It now asks the capability question and, when the route names a record,
     * additionally requires that the record is one this user can actually reach.
     * Reach is checked here as well as in `mount()` so a crafted URL still cannot
     * open another tenant's project.
     *
     * NOTE: when no record is resolvable at this point — Filament does not
     * guarantee the parameter — we do not guess at reach. The per-record check in
     * `project()` and `mount()` remains the authority; this method only decides
     * whether the user may use project pages at all.
     */
    public static function canAccess(array $parameters = []): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $access = Access::for(auth()->user());

        if (! $access->allows(Capability::PROJECTS_VIEW, 'platform')
            && $access->accessibleProjects()->isEmpty()
        ) {
            return false;
        }

        $record = $parameters['record'] ?? null;
        $project = $record instanceof Project ? $record : null;

        if ($project === null && (is_int($record) || (is_string($record) && $record !== ''))) {
            $project = Project::query()->find($record);
        }

        if ($project !== null && ! $access->canReachProject($project)) {
            return false;
        }

        return true;
    }

    /**
     * 0.6.0 Phase A (§A7) — the shared connection-failure state.
     *
     * What failed → what it means → what to do next, with the database
     * error itself kept behind a Technical details disclosure. This used
     * to append the raw DSN/Postgres error to every project page.
     */
    protected static function connectionError(\Throwable $e): Section
    {
        $payload = \App\Support\NxError::forException(
            __('foundation.db_unreachable_title'),
            __('foundation.db_unreachable_body').' '.__('foundation.db_unreachable_next'),
            $e,
            'project database',
        );

        return Section::make()
            ->schema([
                \Filament\Schemas\Components\View::make('components.nx.error', ['payload' => $payload]),
            ]);
    }

    public static function projectUrl(Project $project, string $page, array $params = []): string
    {
        return ProjectResource::getUrl($page, ['record' => $project, ...$params]);
    }

    /**
     * Empty table state. NOTE: Filament page tables require an Eloquent query —
     * collections 500 (Collection::getModel). An impossible Eloquent query
     * renders the empty state correctly.
     */
    protected function emptyTable(
        Table $table,
        string $heading,
        string $description = ''
    ): Table {
        return $table
            ->query(fn () => Project::query()->whereRaw('1 = 0'))
            ->columns([TextColumn::make('name')->label('—')])
            ->paginated(false)
            ->emptyStateHeading($heading)
            ->emptyStateDescription($description);
    }
}
