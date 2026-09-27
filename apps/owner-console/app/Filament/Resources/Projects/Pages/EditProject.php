<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Same NOT NULL guard as CreateProject (26.1A fix).
        $data['timezone'] = trim((string) ($data['timezone'] ?? '')) !== '' ? $data['timezone'] : 'UTC';
        $data['locale'] = trim((string) ($data['locale'] ?? '')) !== '' ? $data['locale'] : 'en';

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
