<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(128),
                TextInput::make('slug')->required()->maxLength(64)->regex('/^[a-z][a-z0-9-]{0,63}$/')
                    ->helperText('kebab-case; used for DB names, prefixes, and URLs.'),
                Select::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'])->required(),
                Select::make('environment')->options(['local' => 'Local', 'staging' => 'Staging', 'production' => 'Production'])->required(),
                TextInput::make('domain')->maxLength(255)->nullable(),
                TextInput::make('api_domain')->maxLength(255)->nullable(),
                TextInput::make('db_name')->maxLength(64)->helperText('Managed by onboarding; edit only to attach an existing database.'),
                TextInput::make('redis_prefix')->maxLength(64),
                Select::make('storage_disk')->options(['local' => 'Local', 's3' => 'S3-compatible'])->required(),
                // Defaults are prefilled in the form AND normalized server-side
                // (CreateProject/EditProject) — a blank submission must never
                // violate the NOT NULL columns (real UI bug found by 26.1A).
                TextInput::make('timezone')->default('UTC')->maxLength(64),
                TextInput::make('locale')->default('en')->maxLength(16),
                Textarea::make('notes')->rows(2)->maxLength(2000)->columnSpanFull(),
            ])->columns(2);
    }
}
