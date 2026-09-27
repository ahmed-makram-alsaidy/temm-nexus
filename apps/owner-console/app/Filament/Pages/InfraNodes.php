<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureNode;
use App\Services\ControlPlane\CpAccess;
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
        return 'Infrastructure Nodes';
    }

    public function getBreadcrumbs(): array
    {
        return ['Infrastructure', 'Nodes'];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make('Nodes (profile: '.e(config('infrastructure.profile', 'single')).')')
                ->description('Local/demo model only — no servers are provisioned from here. Agents report outbound; the Control Plane never shells into a node.')
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
                TextColumn::make('roles')->state(fn (InfrastructureNode $r) => implode(' · ', $r->roles ?? []))->wrap(),
                TextColumn::make('environment')->badge(),
                TextColumn::make('live')->label('Status')
                    ->state(fn (InfrastructureNode $r) => $r->computedStatus())->badge()
                    ->color(fn ($s) => $s === 'healthy' ? 'success' : ($s === 'unknown' ? 'warning' : 'danger')),
                TextColumn::make('cpu_pct')->label('CPU %')->placeholder('—'),
                TextColumn::make('ram_pct')->label('RAM %')->placeholder('—'),
                TextColumn::make('disk_pct')->label('Disk %')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->placeholder('never'),
            ])
            ->recordActions([
                Action::make('open')->label('Detail')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (InfrastructureNode $record) => InfraNodeDetail::getUrl(['node' => $record->name])),
            ])
            ->paginated(false);
    }
}
