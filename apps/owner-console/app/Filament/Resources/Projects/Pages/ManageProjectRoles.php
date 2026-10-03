<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Models\ProjectRecord;
use App\Services\ControlPlane\ProjectAuthManager;
use App\Services\ControlPlane\ProjectConnectionManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Project roles manager. Works when the project has a `roles` table
 * (id, name unique, description?, timestamps?); otherwise shows a setup hint.
 */
class ManageProjectRoles extends Page implements HasTable
{
    use HasProjectContext;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.roles');
    }

    public function getBreadcrumbs(): array
    {
        return ['Roles'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('roles'), EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        try {
            $manager = ProjectAuthManager::for($this->project());
            $missing = ! $manager->tableExists('roles');
        } catch (\Throwable) {
            $missing = false;
        }
        if (! $missing || ! \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'users.manage')) {
            return [];
        }

        return [
            Action::make('bootstrap_roles')->label(__('labels.setup_roles_table'))->icon('heroicon-o-wrench-screwdriver')
                ->action(function () {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'users.manage');
                    $created = \App\Services\ControlPlane\AuthBootstrapService::ensureTables($this->project());
                    $this->audit('ROLE_CREATED', 'role', null, ['bootstrapped' => $created]);
                    Notification::make()->title(__('labels.roles_table_ready'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        try {
            $manager = ProjectAuthManager::for($this->project());
        } catch (\Throwable) {
            return $this->emptyTable($table, 'Roles unavailable', 'Project database unreachable.');
        }
        if (! $manager->tableExists('roles')) {
            return $this->emptyTable($table, 'Roles unavailable', 'No roles table. Create a `roles` table (id, name unique, description) in the project to manage roles here.');
        }

        $model = ProjectRecord::onTable(ProjectConnectionManager::connection($this->project()), 'roles', 'id');

        return $table
            ->query(fn () => $model->newQuery())
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->sortable()->badge(),
                TextColumn::make('description')->limit(60)->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                Action::make('edit_role')->label(__('labels.edit'))->icon('heroicon-o-pencil-square')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(64),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ])
                    ->fillForm(fn ($record) => $record->only(['name', 'description']))
                    ->action(function (array $data, $record) {
                        $record->fill($data);
                        $record->save();
                        $this->audit('ROLE_UPDATED', 'role', $record->getKey(), ['name' => $data['name']]);
                        Notification::make()->title(__('labels.role_updated'))->success()->send();
                    }),
                Action::make('delete_role')->label(__('labels.delete'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('labels.deleting_a_role_does_not_touch_users_rea'))
                    ->action(function ($record) {
                        $name = $record->name;
                        $record->delete();
                        $this->audit('ROLE_DELETED', 'role', $name);
                        Notification::make()->title(__('labels.role_deleted'))->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('create_role')->label(__('labels.new_role'))
                    ->schema([
                        TextInput::make('name')->required()->maxLength(64),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        // Uniqueness is enforced on the project connection (never the console DB).
                        $exists = ProjectRecord::onTable(ProjectConnectionManager::connection($this->project()), 'roles', 'id')
                            ->newQuery()->where('name', $data['name'])->exists();
                        abort_if($exists, 422, 'Role name already exists.');
                        $role = ProjectRecord::onTable(ProjectConnectionManager::connection($this->project()), 'roles', 'id');
                        $role->fill($data);
                        $role->save();
                        $this->audit('ROLE_CREATED', 'role', $role->getKey(), ['name' => $data['name']]);
                        Notification::make()->title(__('labels.role_created'))->success()->send();
                    }),
            ]);
    }
}
