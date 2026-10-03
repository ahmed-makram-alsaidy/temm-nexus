<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use Illuminate\Contracts\Support\Htmlable;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\RealtimeService;
use App\Services\ControlPlane\ReverbStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Realtime status: Reverb configuration (states only, never secrets) plus the
 * last WebSocket handshake/event proof recorded for the project.
 */
class ProjectRealtime extends Page
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
        return __('labels.realtime');
    }

    public function getBreadcrumbs(): array
    {
        return ['Realtime'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('realtime'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $status = ReverbStatusService::for($this->project())->status();
        $proofFile = \App\Services\ControlPlane\ControlPlanePaths::projectDir($this->project()->slug).'/.control-plane/reverb-proof.json';
        $proof = is_file($proofFile) ? json_decode(file_get_contents($proofFile), true) : null;

        $events = \App\Models\RealtimeEvent::query()->where('project_id', $this->project()->id)
            ->orderByDesc('id')->limit(20)->get();
        $channels = $events->groupBy('channel')->map->count()->sortDesc();
        $connectedLine = $status['host']
            ? 'Endpoint configured — live client count needs a running broker probe (see connection check).'
            : 'No endpoint configured yet.';

        $rows = '';
        foreach ($events as $e) {
            $badge = $e->verified
                ? '<span class="cp-badge is-success">verified</span>'
                : '<span class="cp-badge">recorded</span>';
            $rows .= '<tr><td style="white-space:nowrap">'.e($e->created_at?->format('M j, H:i:s') ?? '—').'</td>'
                .'<td><code>'.e($e->channel).'</code></td><td><code>'.e($e->event).'</code></td>'
                .'<td><code>'.e(mb_substr(json_encode($e->payload), 0, 160)).'</code></td>'
                .'<td>'.$badge.'</td></tr>';
        }
        $eventsHtml = $rows !== ''
            ? '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                .'<th>Time</th><th>Channel</th><th>Event</th><th>Payload</th><th>Delivery</th>'
                .'</tr></thead><tbody>'.$rows.'</tbody></table></div>'
            : '<div class="cp-empty"><div class="cp-empty__icon">≋</div>'
                .'<div class="cp-empty__title">No events yet</div>'
                .'<div class="cp-empty__hint">Publish your first test event to see it here with channel, payload and delivery state.</div></div>';

        $channelHtml = $channels->isEmpty()
            ? '<div class="cp-empty__hint">No channels observed in the recent window.</div>'
            : '<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>Channel</th><th class="cp-num">Events</th></tr></thead><tbody>'
                .collect($channels)->map(fn ($c, $ch) => '<tr><td><code>'.e($ch).'</code></td><td class="cp-num">'.(int) $c.'</td></tr>')->implode('')
                .'</tbody></table></div>';

        $proofBadge = $proof ? (($proof['ok'] ? 'PASS' : 'FAIL').' · '.($proof['ran_at'] ?? '—')) : 'Not run yet';
        $proofDetail = $proof ? e($proof['detail'] ?? '') : 'Run a publish to record a fresh check.';

        return $schema->components([
            Section::make('Status')->schema([
                TextEntry::make('installed')->label(__('labels.server'))->state($status['installed'] ? 'Installed' : 'Not installed')
                    ->badge()->color($status['installed'] ? 'success' : 'gray'),
                TextEntry::make('app_key')->label(__('labels.credentials'))->state($status['app_key']),
                TextEntry::make('endpoint')->label(__('labels.endpoint'))->state($status['host'] ? "{$status['scheme']}://{$status['host']}:{$status['port']}" : '—'),
                TextEntry::make('clients')->label(__('labels.connected_clients'))->state($connectedLine),
            ])->compact(),
            Grid::make(2)->schema([
                Section::make('Channels ('.$channels->count().')')->schema([
                    Html::make($channelHtml),
                ])->compact(),
                Section::make('Subscription tester')->schema([
                    Html::make('<dl class="cp-kv"><dt>Public channels</dt><dd><code>wss://…/app/&lt;key&gt;</code> — subscribe with any WS client.</dd>'
                        .'<dt>Private channels</dt><dd><code>private-*</code> / <code>presence-*</code> need a project API key — anonymous subscribe is refused.</dd>'
                        .'<dt>Verify</dt><dd>Publish below, then filter this page and Logs Explorer by the channel name.</dd></dl>'),
                ])->compact(),
            ]),
            Section::make('Recent events ('.$events->count().')')->schema([
                Html::make($eventsHtml
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)"><code>verified</code> means the event '
                    .'round-tripped through the project broadcaster; <code>recorded</code> means stored + endpoint probed.</p>'
                    .'<details class="cp-details"><summary>Connection check · '.e($proofBadge).'</summary>'
                    .'<p style="font-size:.75rem">'.e($proofDetail).'</p></details>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        if (! CpAccess::allows(auth()->user(), 'functions.invoke')) {
            return [];
        }

        return [
            Action::make('publish')->label(__('labels.publish_test_event'))->icon('heroicon-o-signal')
                ->slideOver()
                ->schema([
                    TextInput::make('channel')->required()->default('demo.orders')->maxLength(160),
                    TextInput::make('event')->required()->default('DemoOrderCreated')->maxLength(160),
                    Textarea::make('payload')->label(__('labels.payload_json'))->rows(4)->default('{"order_id": 1}')
                        ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false']),
                    TextInput::make('credential')->label(__('labels.api_key_private_channels_only'))->password()->revealable(),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'functions.invoke');
                    $result = RealtimeService::publishTest(
                        $this->project(), $data['channel'], $data['event'],
                        $data['payload'] ?? '{}', $data['credential'] ?: null
                    );
                    $note = Notification::make()->title(__('labels.event_frag').$result['level'].': '.$result['detail']);
                    $result['level'] === 'recorded' ? $note->warning()->send() : $note->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                }),
        ];
    }
}
