<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Actions\Action;

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

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'Project Switcher';
    }

    public function getBreadcrumbs(): array
    {
        return ['Project Switcher'];
    }

    public static function canAccess(): bool
    {
        return auth()->check() && (bool) auth()->user()->is_admin;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make('Choose a project workspace')->schema([
                \Filament\Schemas\Components\EmbeddedTable::make(),
            ])->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Project::query()->orderBy('name'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('api_domain')->placeholder('—')->toggleable(),
                TextColumn::make('health_status')->badge()
                    ->color(fn ($s) => $s === 'healthy' ? 'success' : ($s === 'unhealthy' ? 'danger' : 'warning')),
            ])
            ->recordActions([
                Action::make('open')->label('Open workspace')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Project $record) => ProjectResource::getUrl('overview', ['record' => $record])),
            ])
            ->paginated(false);
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
