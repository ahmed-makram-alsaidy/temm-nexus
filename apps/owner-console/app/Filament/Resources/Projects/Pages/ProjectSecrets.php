<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectSecret;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\SecretService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * Phase 20M Secrets Vault: names, states, rotation metadata — values are
 * write-only (create/rotate) and NEVER rendered back, in the UI or in logs.
 */
class ProjectSecrets extends Page
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
        return __('labels.secrets');
    }

    public function getBreadcrumbs(): array
    {
        return ['Secrets'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'secrets.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--admin'])->components([$this->subnavSection('secrets'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $secrets = ProjectSecret::query()->where('project_id', $this->project()->id)->orderBy('name')->get();
        $rows = '';
        foreach ($secrets as $s) {
            $rows .= '<tr><td><code>'.e($s->name).'</code></td>'
                .'<td><span class="cp-badge is-success">Configured</span></td>'
                .'<td>'.e($s->env).'</td>'
                .'<td>'.e($s->updated_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td>'.e($s->last_rotated_at?->format('M j, H:i') ?? '—').'</td>'
                .'<td>'.e(mb_substr((string) $s->description, 0, 80)).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">No secrets yet. Values are write-only and never displayed after creation.</td></tr>';
        }
        $grid = '<div class="cp-toolbar"><span class="cp-toolbar__count">'.$secrets->count().' secrets</span></div>'
            .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
            .'<th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.th_env')).'</th><th>'.e(__('labels.th_updated')).'</th><th>'.e(__('labels.th_last_rotated')).'</th><th>'.e(__('labels.th_description')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Encrypted at rest (APP_KEY, server env only). '
            .'Consumed server-side by Server Functions (<code>{{secrets.NAME}}</code>), scheduled tasks and the API tester. '
            .'Key-management note: rotation limits blast radius; the encryption key lives alongside the host — '
            .'documented limitation, see 20M report section.</p>';

        return $schema->components([
            Section::make('Secrets Vault')->schema([Html::make($grid)])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $project = $this->project();
        $options = ProjectSecret::query()->where('project_id', $project->id)
            ->orderBy('name')->pluck('name', 'id')->all();

        return array_filter([
            Action::make('new_secret')->label(__('labels.new_secret'))->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->required()->placeholder('STRIPE_SECRET')
                        ->helperText(__('labels.upper_snake_case_the_value_can_never_be_')),
                    Textarea::make('value')->label(__('labels.value'))->required()->rows(3),
                    TextInput::make('description')->maxLength(255),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'secrets.manage');
                    SecretService::create($this->project(), $data['name'], $data['value'], $data['description'] ?? null);
                    Notification::make()->title(__('labels.secret_stored_write_only'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $options === [] ? null : Action::make('rotate')->label(__('labels.rotate'))
                ->color('warning')->requiresConfirmation()
                ->schema([
                    Select::make('id')->label(__('labels.secret'))->required()->options($options),
                    Textarea::make('value')->label(__('labels.new_value'))->required()->rows(3),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'secrets.manage');
                    $secret = ProjectSecret::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    SecretService::rotate($secret, $data['value']);
                    Notification::make()->title("Secret {$secret->name} rotated")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $options === [] ? null : Action::make('delete')->label(__('labels.delete'))
                ->color('danger')->requiresConfirmation()
                ->modalDescription(__('labels.removes_the_secret_functions_referencing'))
                ->schema([Select::make('id')->label(__('labels.secret'))->required()->options($options)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'secrets.manage');
                    $secret = ProjectSecret::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    SecretService::delete($secret);
                    Notification::make()->title(__('labels.secret_deleted'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }
}
