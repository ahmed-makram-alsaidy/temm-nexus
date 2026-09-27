<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Blank optional fields must never violate the NOT NULL columns —
        // an explicit null bypasses the database default (26.1A fix).
        $data['timezone'] = trim((string) ($data['timezone'] ?? '')) !== '' ? $data['timezone'] : 'UTC';
        $data['locale'] = trim((string) ($data['locale'] ?? '')) !== '' ? $data['locale'] : 'en';

        // Namespace derivation mirrors the onboarding wizard so a project
        // created directly through this form is fully provisioned (26.1A.1).
        $slug = (string) ($data['slug'] ?? '');
        if (blank($data['db_name'] ?? null) && $slug !== '') {
            $data['db_name'] = str_replace('-', '_', $slug).'_db';
        }
        if (blank($data['redis_prefix'] ?? null) && $slug !== '') {
            $data['redis_prefix'] = str_replace('-', '', $slug);
        }

        return $data;
    }
}
