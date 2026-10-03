<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectWebhook;
use App\Models\WebhookDelivery;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\WebhookService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Illuminate\Support\Str;

/**
 * Phase 20P project webhooks: signed delivery, retry policy, invocation log.
 * SSRF-guarded targets; secrets write-only; payloads redacted + capped.
 */
class ProjectWebhooks extends Page
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
        return __('labels.webhooks');
    }

    public function getBreadcrumbs(): array
    {
        return ['Webhooks'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'webhooks.manage');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('webhooks'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $webhooks = ProjectWebhook::query()->where('project_id', $this->project()->id)->orderBy('name')->get();
        $rows = '';
        foreach ($webhooks as $w) {
            $state = $w->enabled ? '<span class="cp-badge is-success">enabled</span>' : '<span class="cp-badge">disabled</span>';
            $rows .= '<tr><td><strong>'.e($w->name).'</strong><br><code style="font-size:.7rem">'.e($w->url).'</code></td>'
                .'<td>'.e(implode(', ', $w->events ?? [])).'</td><td>'.$state.'</td>'
                .'<td class="cp-num">'.(int) $w->max_attempts.'×</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">No webhooks yet. Events: '.e(implode(', ', WebhookService::EVENTS)).'.</td></tr>';
        }
        $log = '';
        foreach (
            WebhookDelivery::query()->whereIn('webhook_id', $webhooks->pluck('id'))
                ->orderByDesc('id')->limit(20)->get() as $d
        ) {
            $badge = $d->status === 'delivered' ? 'is-success' : ($d->status === 'exhausted' ? 'is-danger' : 'is-warning');
            $log .= '<tr><td>'.e($d->created_at?->format('H:i:s') ?? '—').'</td>'
                .'<td>'.e($webhooks->firstWhere('id', $d->webhook_id)?->name ?? '#'.$d->webhook_id).'</td>'
                .'<td><code>'.e($d->event).'</code></td>'
                .'<td><span class="cp-badge '.$badge.'">'.$d->status.'</span></td>'
                .'<td class="cp-num">'.($d->http_code ?? '—').'</td>'
                .'<td class="cp-num">'.(int) $d->attempts.'</td>'
                .'<td>'.(int) $d->duration_ms.' ms</td>'
                .'<td><code>'.e($d->request_id).'</code></td></tr>';
        }

        return $schema->components([
            Section::make('Webhooks ('.$webhooks->count().')')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.webhook')).'</th>.'.e(__('labels.')).'</th><th>'.e(__('labels.state')).'<th><th class="cp-num">Retries</th>'
                    .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Signed (HMAC-SHA256) deliveries with backoff retries. '
                    .'Targets are SSRF-guarded: allowlisted platform hosts or public IPs only — localhost, private ranges, '
                    .'metadata endpoints and raw IPs are refused.</p>'),
            ]),
            Section::make('Delivery log')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.time')).'</th>'.e(__('labels.webhook')).'<th>'.e(__('labels.event')).'<th>'.e(__('labels.status')).'<th><th class="cp-num">Code</th>'
                    .'<th class="cp-num">Attempts</th>.'.e(__('labels.')).'</th><th>'.e(__('labels.request_id')).'<th>'
                    .'</tr></thead><tbody>'.($log ?: '<tr><td colspan="8">No deliveries yet.</td></tr>').'</tbody></table></div>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $webhooks = ProjectWebhook::query()->where('project_id', $this->project()->id)
            ->orderBy('name')->pluck('name', 'id')->all();

        return array_filter([
            Action::make('new_webhook')->label(__('labels.new_webhook'))->icon('heroicon-o-plus')
                ->slideOver()
                ->schema([
                    TextInput::make('name')->required()->maxLength(120),
                    TextInput::make('url')->label(__('labels.target_url'))->required()->maxLength(2048)
                        ->placeholder('https://…'),
                    CheckboxList::make('events')->options(array_combine(WebhookService::EVENTS, WebhookService::EVENTS))
                        ->required()->columns(2),
                    TextInput::make('max_attempts')->label(__('labels.max_attempts'))->numeric()->default(5)->minValue(1)->maxValue(10),
                    TextInput::make('timeout_s')->label(__('labels.timeout_s'))->numeric()->default(10)->minValue(2)->maxValue(30),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'webhooks.manage');
                    WebhookService::validateUrl($this->project(), $data['url']);
                    $webhook = ProjectWebhook::create([
                        'project_id' => $this->project()->id,
                        'name' => $data['name'], 'url' => $data['url'],
                        'events' => array_values($data['events'] ?? []),
                        'enabled' => true,
                        'signing_secret' => Str::random(48),
                        'max_attempts' => (int) ($data['max_attempts'] ?? 5),
                        'timeout_s' => (int) ($data['timeout_s'] ?? 10),
                    ]);
                    $this->audit('WEBHOOK_CREATED', 'webhook', $webhook->id, ['name' => $webhook->name]);
                    Notification::make()->title(__('labels.webhook_created_signing_secret_generated'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $webhooks === [] ? null : Action::make('test_send')->label(__('labels.send_test_event'))
                ->slideOver()
                ->schema([
                    Select::make('id')->label(__('labels.webhook'))->required()->options($webhooks),
                    Select::make('event')->label(__('labels.event'))->required()->options(array_combine(WebhookService::EVENTS, WebhookService::EVENTS)),
                    Textarea::make('payload')->label(__('labels.payload_json'))->rows(3)->default('{"ping": true}')
                        ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false']),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'webhooks.manage');
                    $webhook = ProjectWebhook::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $payload = json_decode((string) ($data['payload'] ?? '{}'), true);
                    abort_if(! is_array($payload), 422, 'Payload must be a JSON object.');
                    $delivery = WebhookService::dispatch($webhook, $data['event'], WebhookService::redactPayload($payload));
                    $note = Notification::make()->title("Delivery {$delivery->status} (HTTP ".($delivery->http_code ?? '—').", {$delivery->attempts} attempt(s))");
                    $delivery->status === 'delivered' ? $note->success()->send() : $note->warning()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $webhooks === [] ? null : Action::make('toggle')->label(__('labels.enable_disable'))
                ->schema([
                    Select::make('id')->label(__('labels.webhook'))->required()->options($webhooks),
                    Select::make('enabled')->required()->options(['1' => 'Enabled', '0' => 'Disabled']),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'webhooks.manage');
                    $webhook = ProjectWebhook::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $webhook->forceFill(['enabled' => $data['enabled'] === '1'])->save();
                    $this->audit('WEBHOOK_UPDATED', 'webhook', $webhook->id, ['enabled' => $webhook->enabled]);
                    Notification::make()->title(__('labels.webhook_updated'))->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
            $webhooks === [] ? null : Action::make('delete')->label(__('labels.delete'))->color('danger')
                ->requiresConfirmation()
                ->schema([Select::make('id')->label(__('labels.webhook'))->required()->options($webhooks)])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'webhooks.manage');
                    $webhook = ProjectWebhook::query()->where('project_id', $this->project()->id)->findOrFail($data['id']);
                    $name = $webhook->name;
                    $webhook->deliveries()->delete();
                    $webhook->delete();
                    $this->audit('WEBHOOK_DELETED', 'webhook', null, ['name' => $name]);
                    Notification::make()->title("Webhook {$name} deleted")->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ]);
    }
}
