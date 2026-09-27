<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectApiKey;
use App\Services\ControlPlane\ApiKeyService;
use App\Services\ControlPlane\CpAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 20J project API keys. Secrets are shown ONCE via a single-use reveal
 * panel, stored hashed, rotatable and revocable. Raw keys are never logged.
 */
class ProjectApiKeys extends Page
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
        return 'API Keys';
    }

    public function getBreadcrumbs(): array
    {
        return ['API Keys'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'keys.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('keys'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $keys = ProjectApiKey::query()->where('project_id', $this->project()->id)->orderByDesc('id')->get();
        $rows = '';
        foreach ($keys as $k) {
            $state = $k->revoked_at ? '<span class="cp-badge is-danger">revoked</span>'
                : ($k->expires_at && $k->expires_at->isPast() ? '<span class="cp-badge is-warning">expired</span>'
                : '<span class="cp-badge is-success">active</span>');
            $rows .= '<tr><td>'.e($k->name).'</td><td><code>'.e($k->prefix).'…</code></td>'
                .'<td>'.e(implode(', ', $k->scopes ?? [])).'</td>'
                .'<td>'.e($k->created_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td>'.e($k->last_used_at?->format('M j, H:i') ?? 'never').'</td>'
                .'<td>'.e($k->expires_at?->format('M j') ?? '—').'</td><td>'.$state.'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7">No API keys yet.</td></tr>';
        }
        $reveal = '';
        if ($rid = request()->query('reveal')) {
            $plain = ApiKeyService::revealOnce((int) request()->query('id'), (string) $rid);
            $reveal = $plain
                ? '<div class="cp-error" style="border-color:rgb(245 158 11 / .5);background:rgb(245 158 11 / .08)">'
                  .'<strong>Copy now — this key is shown ONCE and never again.</strong>'
                  .'<div class="cp-code" style="margin-top:.5rem">'.e($plain).'</div></div>'
                : '<div class="cp-error">Reveal link expired or already used.</div>';
        }
        $grid = $reveal.'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>Name</th><th>Prefix</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Expires</th><th>Status</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>';

        return $schema->components([
            Section::make('API keys')->schema([Html::make($grid)])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();
        $keys = ProjectApiKey::query()->where('project_id', $project->id)->orderByDesc('id')->get();
        $options = [];
        foreach ($keys as $k) {
            if (! $k->revoked_at) {
                $options[(string) $k->id] = $k->name.' ('.$k->prefix.'…)';
            }
        }

        return array_filter([
            Action::make('new_key')->label('New key')->icon('heroicon-o-plus')
                ->slideOver()
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    CheckboxList::make('scopes')->options(array_combine(ApiKeyService::SCOPES, ApiKeyService::SCOPES))
                        ->required()->columns(2)->default(['functions:invoke']),
                    DateTimePicker::make('expires_at')->label('Expires (optional)'),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'keys.manage');
                    $created = ApiKeyService::create(
                        $this->project(), $data['name'], array_values($data['scopes'] ?? []),
                        $data['expires_at'] ?? null
                    );
                    Notification::make()->title('Key created — copy it now, it will never be shown again')->warning()->send();
                    $this->redirect(static::getUrl([
                        'record' => $this->project(),
                        'reveal' => $created['reveal'],
                        'id' => $created['key']->id,
                    ]));
                }),
            $options === [] ? null : Action::make('rotate')->label('Rotate')
                ->color('warning')->requiresConfirmation()
                ->modalDescription('Revokes the selected key and creates a replacement. Update clients immediately.')
                ->schema([\Filament\Forms\Components\Select::make('id')->label('Key')->required()->options($options)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'keys.manage');
                    $key = ProjectApiKey::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $created = ApiKeyService::rotate($key);
                    \App\Services\ControlPlane\AdminAudit::record('APIKEY_ROTATED', $this->project(), 'api_key', $key->id, ['name' => $key->name]);
                    $this->redirect(static::getUrl([
                        'record' => $this->project(), 'reveal' => $created['reveal'], 'id' => $created['key']->id,
                    ]));
                }),
            $options === [] ? null : Action::make('revoke')->label('Revoke')
                ->color('danger')->requiresConfirmation()
                ->schema([\Filament\Forms\Components\Select::make('id')->label('Key')->required()->options($options)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'keys.manage');
                    $key = ProjectApiKey::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    ApiKeyService::revoke($key);
                    Notification::make()->title("Key {$key->name} revoked")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }
}
