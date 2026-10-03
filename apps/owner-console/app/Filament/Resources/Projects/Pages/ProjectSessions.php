<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\ProjectAuthManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Active/recent API sessions from the project's personal_access_tokens table.
 * Revoke = delete the token row (audited). Plaintext tokens are never stored
 * by Sanctum, so nothing secret can leak here.
 */
class ProjectSessions extends Page implements HasTable
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
        return __('labels.sessions');
    }

    public function getBreadcrumbs(): array
    {
        return ['Sessions'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('sessions'), EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        try {
            $manager = ProjectAuthManager::for($this->project());
            $conn = \App\Services\ControlPlane\ProjectConnectionManager::connection($this->project());
        } catch (\Throwable) {
            return $this->emptyTable($table, 'Project database unreachable');
        }

        if (! $manager->hasTokensTable() || ! $manager->hasUsersTable()) {
            return $this->emptyTable($table, 'No session data', 'Requires users + personal_access_tokens tables in the project.');
        }

        $tokens = \App\Models\ProjectRecord::onTable($conn, 'personal_access_tokens', 'id');

        return $table
            ->query(fn () => $tokens->newQuery()
                ->join('users AS u', 'u.id', '=', 'personal_access_tokens.tokenable_id')
                ->select(['personal_access_tokens.id', 'u.email', 'personal_access_tokens.name', 'personal_access_tokens.abilities', 'personal_access_tokens.last_used_at', 'personal_access_tokens.expires_at', 'personal_access_tokens.created_at'])
                ->orderByDesc('personal_access_tokens.created_at'))
            ->columns([
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('name')->label(__('labels.device_client'))->searchable(),
                TextColumn::make('abilities')->limit(40)->placeholder('—'),
                TextColumn::make('last_used_at')->dateTime()->placeholder('never')->sortable(),
                TextColumn::make('expires_at')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('revoke_session')->label(__('labels.revoke'))->icon('heroicon-o-arrow-right-start-on-rectangle')
                    ->color('danger')->requiresConfirmation()
                    ->action(function ($record) {
                        DB::connection(\App\Services\ControlPlane\ProjectConnectionManager::connection($this->project()))
                            ->table('personal_access_tokens')->where('id', $record->id)->limit(1)->delete();
                        $this->audit('TOKEN_REVOKED', 'session', $record->id, ['email' => $record->email]);
                        Notification::make()->title(__('labels.session_revoked'))->success()->send();
                    }),
            ])
            ->emptyStateHeading('No active sessions');
    }
}
