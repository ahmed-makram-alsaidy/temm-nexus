<?php

namespace App\Filament\Pages;

use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\Connectors\ConnectorRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Phase 35F — Connector Catalog.
 *
 * Sections: Installed (registry), Official (first_party), Community
 * (trusted/community), Private/Unverified. Local/catalog-backed only —
 * no commercial billing, no remote marketplace calls (35F).
 */
class ConnectorCatalog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationLabel = 'Connector catalog';

    protected static string|\UnitEnum|null $navigationGroup = 'Migration center';

    protected static ?int $navigationSort = 4;

    public string $activeSection = 'installed';

    public function mount(): void
    {
        abort_unless(CpAccess::allows(auth()->user(), 'projects.view'), 403, 'Missing projects.view permission.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => collect($this->catalogRows()))
            ->columns(fn () => [
                TextColumn::make('key')->label('Key'),
                TextColumn::make('name')->label('Name'),
                TextColumn::make('version')->label('Version'),
                TextColumn::make('trust')->label('Trust')->badge(),
                TextColumn::make('enabled')->label('Enabled')->formatStateUsing(fn ($state) => $state ? 'yes' : 'no'),
            ]);
    }

    /** @return list<array{key: string, name: string, version: string, trust: string, enabled: bool, section: string}> */
    public function catalogRows(): array
    {
        $registry = ConnectorRegistry::instance();
        $rows = [];
        foreach ($registry->connectors() as $connector) {
            $manifest = $connector->manifest();
            $trust = $manifest->trust();
            $rows[] = [
                'key' => $manifest->key(),
                'name' => $connector->definition()->name,
                'version' => $manifest->version(),
                'trust' => $trust,
                'enabled' => $registry->isEnabled($manifest->key()),
                'section' => match ($trust) {
                    'first_party' => 'official',
                    'trusted', 'community' => 'community',
                    default => 'private',
                },
            ];
        }

        return $rows;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('filament.pages.connector-catalog', [
            'rows' => $this->catalogRows(),
            'activeSection' => $this->activeSection,
        ]);
    }
}
