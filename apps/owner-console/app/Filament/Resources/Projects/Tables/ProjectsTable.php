<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Resources\Projects\Pages;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Actions\Action;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->badge()->searchable(),
                TextColumn::make('status')->badge()
                    ->color(fn ($s) => $s === 'active' ? 'success' : ($s === 'paused' ? 'warning' : 'gray')),
                TextColumn::make('health_status')->label('Health')->badge()
                    ->color(fn ($s) => $s === 'healthy' ? 'success' : ($s === 'unhealthy' ? 'danger' : 'warning')),
                TextColumn::make('api_domain')->placeholder('—')->toggleable(),
                TextColumn::make('db_name')->placeholder('—')->toggleable(),
                TextColumn::make('deploy_status')->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived']),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn ($record) => ProjectResource::getUrl('overview', ['record' => $record])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->requiresConfirmation(),
                ]),
            ]);
    }
}
