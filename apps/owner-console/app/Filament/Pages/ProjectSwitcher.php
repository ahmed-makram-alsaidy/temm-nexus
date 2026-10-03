<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use App\Models\Project;
use App\Services\ControlPlane\CpAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Project switcher: searchable project list jumping straight into workspaces.
 */
class ProjectSwitcher extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Project Switcher';

    protected static string|\UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $slug = 'switcher';

    protected static ?int $navigationSort = 5;

    public function getTitle(): string|Htmlable
    {
        return __('labels.project_switcher');
    }

    public function getBreadcrumbs(): array
    {
        return ['Project Switcher'];
    }

    /**
     * 0.4.0 — aligned with every sibling page.
     *
     * This used to gate on the raw `is_admin` flag while InfraNodes,
     * TeamManagement and the rest use `CpAccess`. Those two models disagree:
     * a user granted the modern `owner`/`admin` role without the legacy flag
     * was refused here and allowed there, and vice versa. A page that lists
     * EVERY project must use the same capability vocabulary as the rest of the
     * platform, so it now asks for `projects.view` — and the table below is
     * scoped to reachable projects so it cannot enumerate another tenant's.
     */
    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make('Choose a project workspace')->schema([
                EmbeddedTable::make(),
            ])->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // 0.4.0: scoped to projects this user can actually open. The
            // unscoped `Project::query()` this replaces would have listed every
            // tenant's projects to anyone who reached the page.
            ->query(fn () => PlatformAccess::current()->projectsQuery()->orderBy('name'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('api_domain')->placeholder('—')->toggleable(),
                TextColumn::make('health_status')->badge()
                    ->color(fn ($s) => $s === 'healthy' ? 'success' : ($s === 'unhealthy' ? 'danger' : 'warning')),
            ])
            ->recordActions([
                Action::make('open')->label(__('labels.open_workspace'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Project $record) => ProjectResource::getUrl('overview', ['record' => $record])),
            ])
            ->paginated(false);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
