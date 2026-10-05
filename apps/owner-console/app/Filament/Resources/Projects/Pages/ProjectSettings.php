<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Services\ControlPlane\ControlPlanePaths;
use App\Support\ProductStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 0.6.0 Phase D (§D8) — PROJECT SETTINGS as a hub.
 *
 * The settings tab MAPS configuration instead of exposing every setting as
 * one long page: General stays here (name, environment, domains, defaults,
 * with its edit action), and Environments / Connections / Secrets / Team &
 * access / Advanced are entry cards to the existing dedicated pages. Every
 * destination keeps its own route; every card is filtered server-side
 * through that page's own canAccess() — the same rule the tab bar applies.
 *
 * Secret VALUES are never shown — only presence states (Configured / Not
 * configured) derived from the project .env.
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
        return [__('labels.settings')];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([
            $this->subnavSection('settings'),
            EmbeddedSchema::make('infolist'),
            Html::make(fn (): string => view('filament.projects.settings-hub', $this->hubViewData())->render()),
        ]);
    }

    /**
     * Hub entries, resolved in PHP and filtered server-side.
     *
     * @return array<string, mixed>
     */
    protected function hubViewData(): array
    {
        $project = $this->project();

        $entries = [];
        $add = function (string $page, string $icon) use (&$entries, $project): void {
            $pageClass = ProjectResource::getPages()[$page] ?? null;
            if ($pageClass === null) {
                return;
            }
            $class = $pageClass->getPage();
            try {
                if (! $class::canAccess(['record' => $project->getKey()])) {
                    return;
                }
                $entries[$page] = [
                    'url' => ProjectResource::getUrl($page, ['record' => $project]),
                    'icon' => $icon,
                ];
            } catch (\Throwable) {
                return;
            }
        };

        $add('environments', 'heroicon-o-adjustments-horizontal');
        $add('connections', 'heroicon-o-server-stack');
        $add('secrets', 'heroicon-o-key');
        $add('users', 'heroicon-o-users');
        $add('roles', 'heroicon-o-identification');
        $add('permissions', 'heroicon-o-shield-check');
        $add('db-advanced', 'heroicon-o-wrench-screwdriver');

        return [
            'project' => $project,
            'entries' => $entries,
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        $states = $this->secretStates();

        return $schema
            ->record($p)
            ->components([
                Section::make(__('projects.hub_general'))
                    ->description(__('projects.hub_general_desc'))
                    ->schema([
                        TextEntry::make('name')->label(__('projects.field_name')),
                        // The dictionary owns state words (§A5).
                        TextEntry::make('status')->label(__('projects.field_status'))->badge()
                            ->formatStateUsing(fn (string $state): string => ProductStatus::label($state))
                            ->color(fn (string $state): string => ProductStatus::color($state)),
                        TextEntry::make('environment')->label(__('projects.field_environment'))
                            ->formatStateUsing(fn (string $state): string => __('projects.env_'.$state)),
                        TextEntry::make('domain')->label(__('projects.field_domain'))->placeholder('—')->copyable(),
                        TextEntry::make('api_domain')->label(__('projects.field_api_domain'))->placeholder('—')->copyable(),
                        TextEntry::make('timezone')->label(__('projects.field_timezone')),
                        TextEntry::make('locale')->label(__('projects.field_locale')),
                        TextEntry::make('storage_disk')->label(__('projects.field_storage_disk')),
                        TextEntry::make('notes')->label(__('projects.field_notes'))->placeholder('—'),
                    ])->columns(3)
                    ->headerActions([
                        Action::make('edit_settings')->label(__('labels.edit_settings'))
                            ->schema([
                                TextInput::make('name')->required()->maxLength(128),
                                Select::make('status')->options([
                                    'planned' => ProductStatus::label('planned'),
                                    'active' => ProductStatus::label('active'),
                                    'paused' => ProductStatus::label('paused'),
                                    'archived' => ProductStatus::label('archived'),
                                ])->required(),
                                Select::make('environment')->options([
                                    'local' => __('projects.env_local'),
                                    'staging' => __('projects.env_staging'),
                                    'production' => __('projects.env_production'),
                                ])->required(),
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
                Section::make(__('projects.secret_states_title'))->schema([
                    TextEntry::make('db_password')->label(__('labels.db_password'))->state($states['DB_PASSWORD'] ?? __('status.unknown'))->badge(),
                    TextEntry::make('app_key')->label('APP_KEY')->state($states['APP_KEY'] ?? __('status.unknown'))->badge(),
                    TextEntry::make('redis')->label(__('labels.redis_password'))->state($states['REDIS_PASSWORD'] ?? __('status.unknown'))->badge(),
                    TextEntry::make('reverb')->label(__('labels.reverb_secret'))->state($states['REVERB_APP_SECRET'] ?? __('status.unknown'))->badge(),
                    TextEntry::make('mail')->label(__('labels.mail_password'))->state($states['MAIL_PASSWORD'] ?? __('status.unknown'))->badge(),
                    TextEntry::make('hint')->label(__('labels.rotation'))->state(__('projects.secret_rotation_hint')),
                ])->columns(3),
            ]);
    }

    /** @return array<string,string> env key => state label */
    protected function secretStates(): array
    {
        $envFile = ControlPlanePaths::projectDir($this->project()->slug).'/.env';
        $out = [];
        $keys = ['DB_PASSWORD', 'APP_KEY', 'REDIS_PASSWORD', 'REVERB_APP_SECRET', 'MAIL_PASSWORD'];
        if (! is_file($envFile)) {
            return array_fill_keys($keys, __('projects.secret_no_env'));
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
                ? __('projects.secret_not_configured')
                : __('projects.secret_configured');
        }

        return $out;
    }
}
