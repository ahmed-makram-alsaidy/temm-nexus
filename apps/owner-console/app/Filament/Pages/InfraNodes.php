<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureNode;
use App\Services\ControlPlane\CpAccess;
use App\Support\ProductStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Phase 21B: infrastructure nodes inventory. Status shown is computed from
 * the last heartbeat (stale nodes degrade/offline automatically) — the
 * stored status column is never trusted for display.
 *
 * 0.6.0 Phase H: copy flows through lang/, statuses render through the
 * status dictionary, the empty state explains what belongs here (with the
 * seeding command for single-node installs), and resource columns are
 * toggleable so the default face stays scannable.
 */
class InfraNodes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Nodes';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 70;

    protected static ?string $slug = 'infra-nodes';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.infrastructure_nodes');
    }

    public function getBreadcrumbs(): array
    {
        return [__('settings.system_title'), __('settings.system_nodes')];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make(__('infra.nodes_section_title'))
                ->description(__('infra.nodes_section_description'))
                ->schema([\Filament\Schemas\Components\EmbeddedTable::make()])
                ->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => InfrastructureNode::query()->orderBy('name'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->badge(),
                TextColumn::make('roles')
                    ->label(__('infra.col_roles'))
                    ->state(fn (InfrastructureNode $r) => implode(' · ', $r->roles ?? []))
                    ->wrap()
                    ->toggleable(),
                // Environment is a stored environment identifier, not a
                // status — it renders as entered (exempt from the dictionary).
                TextColumn::make('environment')
                    ->label(__('infra.col_environment'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('live')->label(__('labels.status'))
                    ->state(fn (InfrastructureNode $r) => $r->computedStatus())
                    ->badge()
                    ->formatStateUsing(fn ($s) => ProductStatus::label((string) $s))
                    ->color(fn ($s) => ProductStatus::color((string) $s)),
                TextColumn::make('cpu_pct')->label(__('labels.cpu'))->placeholder('—')->toggleable(),
                TextColumn::make('ram_pct')->label(__('labels.ram'))->placeholder('—')->toggleable(),
                TextColumn::make('disk_pct')->label(__('labels.disk'))->placeholder('—')->toggleable(),
                TextColumn::make('last_seen_at')->label(__('labels.last_seen'))
                    ->dateTime()
                    ->placeholder(__('infra.never_reported')),
            ])
            ->recordActions([
                Action::make('open')->label(__('labels.detail'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (InfrastructureNode $record) => InfraNodeDetail::getUrl(['node' => $record->name])),
            ])
            ->emptyStateHeading(__('infra.nodes_empty_title'))
            ->emptyStateDescription(__('infra.nodes_empty_body')."\n\n".__('infra.nodes_empty_hint'))
            ->paginated(false);
    }
}
