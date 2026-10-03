<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureService;
use App\Services\ControlPlane\CpAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Phase 21B: service assignments (PostgreSQL, Redis, Caddy, Laravel API,
 * Horizon, Reverb, Backup Worker) — which node serves each, endpoint
 * reference, health, version. No credentials are stored or shown.
 */
class InfraServices extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Services';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'infra-services';

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.infrastructure_services');
    }

    public function getBreadcrumbs(): array
    {
        return ['Infrastructure', 'Services'];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make('Service assignments')
                ->description('Endpoint references and health only — never credentials.')
                ->schema([\Filament\Schemas\Components\EmbeddedTable::make()])
                ->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => InfrastructureService::query()->with('node')->orderBy('key'))
            ->columns([
                TextColumn::make('key')->badge()->searchable(),
                TextColumn::make('label')->placeholder('—'),
                TextColumn::make('node.name')->label(__('labels.node'))->badge(),
                TextColumn::make('scope')->badge(),
                TextColumn::make('status')->badge()
                    ->color(fn ($s) => $s === 'healthy' ? 'success' : ($s === 'unknown' ? 'warning' : 'danger')),
                TextColumn::make('endpoint')->placeholder('—'),
                TextColumn::make('version')->placeholder('—'),
                TextColumn::make('last_check_at')->label(__('labels.last_check'))->dateTime()->placeholder('—'),
            ])
            ->paginated(false);
    }
}
