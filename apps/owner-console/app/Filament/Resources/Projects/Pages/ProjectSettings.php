<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Project settings. Secret VALUES are never shown — only presence states
 * (Configured / Not configured / Local only) derived from the project .env.
 */
class ProjectSettings extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('labels.settings');
    }

    public function getBreadcrumbs(): array
    {
        return ['Settings'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('settings'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        $states = $this->secretStates();

        return $schema
            ->record($p)
            ->components([
                Section::make('General')->schema([
                    TextEntry::make('name'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('environment'),
                    TextEntry::make('domain')->placeholder('—')->copyable(),
                    TextEntry::make('api_domain')->placeholder('—')->copyable(),
                    TextEntry::make('timezone'),
                    TextEntry::make('locale'),
                    TextEntry::make('storage_disk'),
                    TextEntry::make('notes')->placeholder('—'),
                ])->columns(3)
                    ->headerActions([
                        Action::make('edit_settings')->label(__('labels.edit_settings'))
                            ->schema([
                                TextInput::make('name')->required()->maxLength(128),
                                Select::make('status')->options(['planned' => 'Planned', 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'])->required(),
                                Select::make('environment')->options(['local' => 'Local', 'staging' => 'Staging', 'production' => 'Production'])->required(),
                                TextInput::make('domain')->maxLength(255)->nullable(),
                                TextInput::make('api_domain')->maxLength(255)->nullable(),
                                TextInput::make('timezone')->required()->maxLength(64),
                                TextInput::make('locale')->required()->maxLength(16),
                                Select::make('storage_disk')->options(['local' => 'Local', 's3' => 'S3-compatible'])->required(),
                                Textarea::make('notes')->rows(2)->maxLength(2000)->nullable(),
                            ])
                            ->fillForm(fn () => $p->only(['name', 'status', 'environment', 'domain', 'api_domain', 'timezone', 'locale', 'storage_disk', 'notes']))
                            ->action(function (array $data) {
                                $this->project()->update($data);
                                $this->audit('PROJECT_SETTINGS_UPDATED', 'project', $this->project()->id, ['fields' => array_keys($data)]);
                                Notification::make()->title(__('labels.settings_saved'))->success()->send();
                                $this->redirect(static::getUrl(['record' => $this->project()]));
                            }),
                    ]),
                Section::make('Secrets (states only — values never displayed)')->schema([
                    TextEntry::make('db_password')->label(__('labels.db_password'))->state($states['DB_PASSWORD'] ?? 'Unknown')->badge(),
                    TextEntry::make('app_key')->label('APP_KEY')->state($states['APP_KEY'] ?? 'Unknown')->badge(),
                    TextEntry::make('redis')->label(__('labels.redis_password'))->state($states['REDIS_PASSWORD'] ?? 'Unknown')->badge(),
                    TextEntry::make('reverb')->label(__('labels.reverb_secret'))->state($states['REVERB_APP_SECRET'] ?? 'Unknown')->badge(),
                    TextEntry::make('mail')->label(__('labels.mail_password'))->state($states['MAIL_PASSWORD'] ?? 'Unknown')->badge(),
                    TextEntry::make('hint')->label(__('labels.rotation'))->state('Rotate via create-project/rotation runbooks; values are never shown here.'),
                ])->columns(3),
            ]);
    }

    /** @return array<string,string> env key => state label */
    protected function secretStates(): array
    {
        $envFile = \App\Services\ControlPlane\ControlPlanePaths::projectDir($this->project()->slug).'/.env';
        $out = [];
        $keys = ['DB_PASSWORD', 'APP_KEY', 'REDIS_PASSWORD', 'REVERB_APP_SECRET', 'MAIL_PASSWORD'];
        if (! is_file($envFile)) {
            return array_fill_keys($keys, 'No .env found');
        }
        $values = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim($v);
        }
        foreach ($keys as $key) {
            $v = $values[$key] ?? '';
            $out[$key] = ($v === '' || str_contains($v, 'CHANGE_ME') || str_contains($v, 'SET_'))
                ? 'Not configured'
                : 'Configured';
        }

        return $out;
    }
}
