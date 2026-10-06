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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Project permissions manager. Works when the project has a `permissions` table
 * (id, name unique, description?); otherwise shows a setup hint.
 */
class ManageProjectPermissions extends Page implements HasTable
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
        return __('labels.permissions');
    }

    public function getBreadcrumbs(): array
    {
        return ['Permissions'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('permissions'), EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        try {
            $manager = ProjectAuthManager::for($this->project());
            $missing = ! $manager->tableExists('permissions');
        } catch (\Throwable) {
            $missing = false;
        }
        if (! $missing || ! \App\Services\ControlPlane\CpAccess::allows(auth()->user(), 'users.manage')) {
            return [];
        }

        return [
            Action::make('bootstrap_permissions')->label(__('labels.setup_permissions_table'))->icon('heroicon-o-wrench-screwdriver')
                ->action(function () {
                    \App\Services\ControlPlane\CpAccess::require(auth()->user(), 'users.manage');
                    $created = \App\Services\ControlPlane\AuthBootstrapService::ensureTables($this->project());
                    $this->audit('PERMISSION_CREATED', 'permission', null, ['bootstrapped' => $created]);
                    Notification::make()->title(__('labels.permissions_table_ready'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        try {
            $manager = ProjectAuthManager::for($this->project());
        } catch (\Throwable) {
            return $this->emptyTable($table, 'Permissions unavailable', 'Project database unreachable.');
        }
        if (! $manager->tableExists('permissions')) {
            return $this->emptyTable($table, 'Permissions unavailable', 'No permissions table. Create a `permissions` table (id, name unique, description) in the project to manage permissions here.');
        }

        $model = ProjectRecord::onTable(ProjectConnectionManager::connection($this->project()), 'permissions', 'id');

        return $table
            ->query(fn () => $model->newQuery())
            ->columns([
                // Internal PK — hidden by default, reachable via the column manager.
                TextColumn::make('id')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')->searchable()->sortable()->badge()->color('info'),
                TextColumn::make('description')->limit(60)->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                Action::make('edit_permission')->label(__('labels.edit'))->icon('heroicon-o-pencil-square')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(128),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ])
                    ->fillForm(fn ($record) => $record->only(['name', 'description']))
                    ->action(function (array $data, $record) {
                        $record->fill($data);
                        $record->save();
                        $this->audit('PERMISSION_UPDATED', 'permission', $record->getKey(), ['name' => $data['name']]);
                        Notification::make()->title(__('labels.permission_updated'))->success()->send();
                    }),
                Action::make('delete_permission')->label(__('labels.delete'))->icon('heroicon-o-trash')->color('danger')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $name = $record->name;
                        $record->delete();
                        $this->audit('PERMISSION_DELETED', 'permission', $name);
                        Notification::make()->title(__('labels.permission_deleted'))->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('create_permission')->label(__('labels.new_permission'))
                    ->schema([
                        TextInput::make('name')->required()->maxLength(128)->helperText(__('labels.explicit_keys_like_orders_create_beat_im')),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        $conn = ProjectConnectionManager::connection($this->project());
                        abort_if(ProjectRecord::onTable($conn, 'permissions', 'id')->newQuery()->where('name', $data['name'])->exists(), 422, 'Permission already exists.');
                        $perm = ProjectRecord::onTable($conn, 'permissions', 'id');
                        $perm->fill($data);
                        $perm->save();
                        $this->audit('PERMISSION_CREATED', 'permission', $perm->getKey(), ['name' => $data['name']]);
                        Notification::make()->title(__('labels.permission_created'))->success()->send();
                    }),
            ]);
    }

}
