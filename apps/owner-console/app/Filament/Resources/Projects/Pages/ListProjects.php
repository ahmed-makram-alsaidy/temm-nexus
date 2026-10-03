<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Pages\NewProjectWizard;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\PlatformAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('nav.projects');
    }

    public function getBreadcrumbs(): array
    {
        return [__('nav.projects')];
    }

    protected function getHeaderActions(): array
    {
        return [
            // 0.4.0-rc.5 (Phase 41, B.1): "New project" is now the guided
            // wizard. The classic single-form CreateProject page remains
            // reachable for power users via the resource route.
            Action::make('new_project_wizard')
                ->label(__('wizard.title'))
                ->icon('heroicon-o-plus')
                ->visible(fn () => PlatformAccess::current()->canCreateProject())
                ->url(fn () => NewProjectWizard::getUrl()),
        ];
    }
}
