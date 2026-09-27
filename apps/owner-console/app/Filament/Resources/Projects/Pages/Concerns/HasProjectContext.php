<?php

namespace App\Filament\Resources\Projects\Pages\Concerns;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Services\ControlPlane\AdminAudit;
use App\Services\ControlPlane\ProjectConnectionManager;
use App\Services\ControlPlane\ProjectDatabaseExplorer;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;

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

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->check() && (bool) auth()->user()->is_admin;
    }

    protected static function connectionError(\Throwable $e): Section
    {
        return Section::make('Project database unreachable')
            ->description('Check that the project database exists and PROJECT_* credentials are configured in the console .env. ('.$e->getMessage().')')
            ->icon('heroicon-o-exclamation-triangle')
            ->collapsed(false);
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
        \Filament\Tables\Table $table,
        string $heading,
        string $description = ''
    ): \Filament\Tables\Table {
        return $table
            ->query(fn () => Project::query()->whereRaw('1 = 0'))
            ->columns([\Filament\Tables\Columns\TextColumn::make('name')->label('—')])
            ->paginated(false)
            ->emptyStateHeading($heading)
            ->emptyStateDescription($description);
    }
}
