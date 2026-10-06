<?php

namespace App\Filament\Pages;

use App\Models\InfrastructureService;
use App\Services\ControlPlane\CpAccess;
use App\Support\ProductStatus;
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
 *
 * 0.6.0 Phase H: statuses through ProductStatus, copy through lang/,
 * toggleable secondary columns, meaningful empty state.
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
        return [__('settings.system_title'), __('settings.system_services')];
    }

    public static function canAccess(): bool
    {
        return CpAccess::allows(auth()->user(), 'infrastructure.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            Section::make(__('infra.services_section_title'))
                ->description(__('infra.services_section_description'))
                ->schema([\Filament\Schemas\Components\EmbeddedTable::make()])
                ->compact(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => InfrastructureService::query()->with('node')->orderBy('key'))
            ->columns([
                TextColumn::make('key')->label(__('infra.col_service'))->badge()->searchable(),
                TextColumn::make('label')->label(__('infra.col_label'))->placeholder('—'),
                TextColumn::make('node.name')->label(__('infra.col_node'))->badge(),
                TextColumn::make('scope')->label(__('infra.col_scope'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('infra.col_status'))
                    ->badge()
                    ->formatStateUsing(fn ($s) => ProductStatus::label((string) $s))
                    ->color(fn ($s) => ProductStatus::color((string) $s)),
                TextColumn::make('endpoint')->label(__('infra.col_endpoint'))->placeholder('—')->toggleable(),
                TextColumn::make('version')->label(__('infra.col_version'))->placeholder('—')->toggleable(),
                TextColumn::make('last_check_at')->label(__('infra.col_last_check'))
                    ->since()
                    ->tooltip(fn ($state) => $state?->format('Y-m-d H:i:s'))
                    ->placeholder(__('infra.never_reported')),
            ])
            ->emptyStateHeading(__('infra.services_empty_title'))
            ->emptyStateDescription(__('infra.services_empty_body'))
            ->paginated(false);
    }
}
