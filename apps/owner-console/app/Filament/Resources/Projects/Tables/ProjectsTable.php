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
                // 0.6.0 Phase A (§A5): raw enums are never the user-facing
                // label — the status dictionary owns both word and tone.
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => \App\Support\ProductStatus::label($state))
                    ->color(fn (string $state): string => \App\Support\ProductStatus::color($state)),
                TextColumn::make('health_status')->label(__('labels.health'))->badge()
                    ->formatStateUsing(fn (string $state): string => \App\Support\ProductStatus::label($state))
                    ->color(fn (string $state): string => \App\Support\ProductStatus::color($state)),
                TextColumn::make('api_domain')->placeholder('—')->toggleable(),
                TextColumn::make('db_name')->placeholder('—')->toggleable(),
                TextColumn::make('deploy_status')->badge()->toggleable()
                    ->formatStateUsing(fn (string $state): string => \App\Support\ProductStatus::label($state))
                    ->color(fn (string $state): string => \App\Support\ProductStatus::color($state)),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'planned' => \App\Support\ProductStatus::label('planned'),
                    'active' => \App\Support\ProductStatus::label('active'),
                    'paused' => \App\Support\ProductStatus::label('paused'),
                    'archived' => \App\Support\ProductStatus::label('archived'),
                ]),
            ])
            ->recordActions([
                Action::make('open')->label(__('labels.open'))->icon('heroicon-o-arrow-top-right-on-square')
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
