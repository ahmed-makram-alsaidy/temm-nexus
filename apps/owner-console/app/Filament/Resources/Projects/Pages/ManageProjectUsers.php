<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Models\ProjectRecord;
use App\Services\ControlPlane\ProjectAuthManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ManageProjectUsers extends Page implements HasTable
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
        return __('labels.users');
    }

    public function getBreadcrumbs(): array
    {
        return ['Users'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('users'),
            EmbeddedTable::make(),
        ]);
    }

    protected function manager(): ?ProjectAuthManager
    {
        try {
            return ProjectAuthManager::for($this->project());
        } catch (\Throwable) {
            return null;
        }
    }

    public function table(Table $table): Table
    {
        $manager = $this->manager();
        if (! $manager || ! $manager->hasUsersTable()) {
            return $this->emptyTable($table, 'No users table in this project', 'The project database has no users table yet.');
        }

        $conn = \App\Services\ControlPlane\ProjectConnectionManager::connection($this->project());
        $model = ProjectRecord::onTable($conn, 'users', 'id');
        $hasStatus = in_array('status', $manager->userColumns(), true);
        $hasRole = in_array('role', $manager->userColumns(), true);

        return $table
            ->query(fn () => $model->newQuery()->select($manager->userColumns()))
            ->columns([
                TextColumn::make('id')->sortable()->toggleable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'disabled' ? 'danger' : 'success')
                    ->visible($hasStatus),
                TextColumn::make('role')->badge()->visible($hasRole),
                IconColumn::make('email_verified_at')->label(__('labels.verified'))->boolean()
                    ->trueIcon('heroicon-o-check-badge')->falseIcon('heroicon-o-x-mark')
                    ->getStateUsing(fn ($record) => $record->email_verified_at !== null),
                TextColumn::make('last_login_at')->dateTime()->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'disabled' => 'Disabled'])->visible($hasStatus),
                SelectFilter::make('role')->options(['admin' => 'Admin', 'merchant' => 'Merchant', 'courier' => 'Courier', 'customer' => 'Customer', 'employee' => 'Employee'])->visible($hasRole),
            ])
            ->recordActions([
                Action::make('view_user')
                    ->label(__('labels.view'))
                    ->icon('heroicon-o-eye')
                    ->slideOver()
                    ->schema(fn ($record) => $this->userForm(true))
                    ->fillForm(fn ($record) => $this->userAttributes($record))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('edit_user')
                    ->label(__('labels.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->slideOver()
                    ->schema(fn ($record) => $this->userForm(false))
                    ->fillForm(fn ($record) => $this->userAttributes($record))
                    ->action(function (array $data, $record) {
                        ProjectAuthManager::for($this->project())->updateUser($record->getKey(), $data);
                        $this->audit('USER_UPDATED', 'user', $record->getKey(), ['fields' => array_keys($data)]);
                        Notification::make()->title(__('labels.user_updated'))->success()->send();
                    }),
                Action::make('toggle_status')
                    ->label(fn ($record) => ProjectAuthManager::for($this->project())->isDisabled($record) ? 'Enable' : 'Disable')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn ($record) => ProjectAuthManager::for($this->project())->isDisabled($record) ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->visible(fn () => in_array('status', ProjectAuthManager::for($this->project())->userColumns(), true))
                    ->action(function ($record) {
                        $m = ProjectAuthManager::for($this->project());
                        $disabled = $m->isDisabled($record);
                        $m->setStatus($record->getKey(), $disabled ? 'active' : 'disabled');
                        if (! $disabled) {
                            $m->revokeAllTokens($record->getKey());
                        }
                        $this->audit($disabled ? 'USER_ENABLED' : 'USER_DISABLED', 'user', $record->getKey());
                        Notification::make()->title($disabled ? 'User enabled' : 'User disabled, sessions revoked')->success()->send();
                    }),
                Action::make('revoke_tokens')
                    ->label(__('labels.revoke_sessions'))
                    ->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->requiresConfirmation()
                    ->visible(fn () => ProjectAuthManager::for($this->project())->hasTokensTable())
                    ->action(function ($record) {
                        $n = ProjectAuthManager::for($this->project())->revokeAllTokens($record->getKey());
                        $this->audit('SESSION_REVOKED', 'user', $record->getKey(), ['tokens_revoked' => $n]);
                        Notification::make()->title("Revoked {$n} session(s)")->success()->send();
                    }),
            ])
            ->headerActions([
                Action::make('create_user')
                    ->label(__('labels.new_user'))
                    ->slideOver()
                    ->schema($this->userForm(false, true))
                    ->action(function (array $data) {
                        $id = ProjectAuthManager::for($this->project())->createUser($data);
                        $this->audit('USER_CREATED', 'user', $id, ['email' => $data['email']]);
                        Notification::make()->title(__('labels.user_created'))->success()->send();
                    }),
            ]);
    }

    protected function userForm(bool $readOnly, bool $isCreate = false): array
    {
        $cols = ProjectAuthManager::for($this->project())->userColumns();
        $fields = [
            TextInput::make('name')->required(! $readOnly)->disabled($readOnly),
            TextInput::make('email')->email()->required(! $readOnly)->disabled($readOnly),
        ];
        if (in_array('role', $cols, true)) {
            $fields[] = Select::make('role')
                ->options(['admin' => 'Admin', 'merchant' => 'Merchant', 'courier' => 'Courier', 'customer' => 'Customer', 'employee' => 'Employee'])
                ->disabled($readOnly);
        }
        if (in_array('status', $cols, true)) {
            $fields[] = Select::make('status')->options(['active' => 'Active', 'disabled' => 'Disabled'])->disabled($readOnly);
        }
        $fields[] = Toggle::make('verified')->label(__('labels.email_verified'))->disabled($readOnly);
        if (! $readOnly) {
            $fields[] = TextInput::make('password')->password()
                ->required($isCreate)->minLength(8)
                ->helperText($isCreate ? 'Min 8 characters.' : 'Leave blank to keep the current password.');
        }

        return $fields;
    }

    protected function userAttributes($record): array
    {
        return [
            'name' => $record->name ?? null,
            'email' => $record->email ?? null,
            'role' => $record->role ?? null,
            'status' => $record->status ?? null,
            'verified' => $record->email_verified_at !== null,
        ];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->check() && (bool) auth()->user()->is_admin;
    }
}
