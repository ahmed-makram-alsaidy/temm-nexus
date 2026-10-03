<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

class AuditLogList extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('labels.audit_log');
    }

    public function getBreadcrumbs(): array
    {
        return ['Audit Log'];
    }
}
