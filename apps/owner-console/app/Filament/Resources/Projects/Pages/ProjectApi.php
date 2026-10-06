<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ApiRouteSnapshot;
use App\Services\ControlPlane\ApiStudioService;
use App\Services\ControlPlane\CpAccess;
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
 * Phase 20I API Studio: base URL + health + auth strategy, grouped endpoint
 * explorer from an in-GUI refreshed snapshot, interactive SSRF-guarded
 * request tester, downloadable OpenAPI. Internal routes never listed.
 */
class ProjectApi extends Page
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
        return 'API';
    }

    public function getBreadcrumbs(): array
    {
        return ['API'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('api'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        $snapshot = ApiRouteSnapshot::query()->where('project_id', $p->id)->first();
        $routes = $snapshot->routes ?? [];
        $grouped = [];
        foreach ($routes as $r) {
            $segment = explode('/', trim($r['uri'], '/'))[1] ?? 'root';
            $grouped[$segment][] = $r;
        }
        ksort($grouped);
        $explorer = '';
        $first = true;
        foreach ($grouped as $group => $list) {
            $explorer .= '<details class="cp-details"'.($first ? ' open' : '').'><summary>'.e($group)
                .' <span class="cp-badge">'.count($list).'</span></summary>'
                .'<div class="cp-tablewrap"><table class="cp-grid"><thead><tr><th>'.e(__('labels.th_method')).'</th><th>'.e(__('labels.endpoint')).'</th><th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.th_auth')).'</th></tr></thead><tbody>';
            foreach ($list as $r) {
                $methodColor = $r['method'] === 'GET' ? 'is-info' : ($r['method'] === 'POST' ? 'is-success' : 'is-warning');
                $authBadge = $r['auth'] === 'auth' ? '<span class="cp-badge is-danger">auth</span>' : '<span class="cp-badge">public</span>';
                $explorer .= '<tr><td><span class="cp-badge '.$methodColor.'">'.e($r['method']).'</span></td>'
                    .'<td><code>'.e($r['uri']).'</code></td><td>'.e($r['name'] ?? '—').'</td><td>'.$authBadge.'</td></tr>';
            }
            $explorer .= '</tbody></table></div></details>';
            $first = false;
        }
        if ($explorer === '') {
            $explorer = '<div class="cp-empty"><div class="cp-empty__icon">⌁</div>'
                .'<div class="cp-empty__title">No endpoint snapshot yet</div>'
                .'<div class="cp-empty__hint">This is expected before the first refresh. Use <strong>Refresh snapshot</strong> to capture the project routes — no terminal needed.</div></div>';
        } else {
            $explorer = '<div class="cp-toolbar"><span class="cp-toolbar__count">'.count($routes).' routes · '.count($grouped).' groups</span></div>'.$explorer;
        }
        $last = session('cp_api_result');
        if ($last) {
            $ok = $last['status'] > 0 && $last['status'] < 400;
            $result = '<div class="cp-toolbar"><span class="cp-badge '.($ok ? 'is-success' : 'is-danger').'">HTTP '.(int) $last['status'].'</span>'
                .'<span class="cp-toolbar__count">'.(int) $last['duration_ms'].' ms · <code>'.e($last['request_id'] ?? '—').'</code></span></div>'
                .'<div class="cp-code">'.e(mb_substr((string) ($last['error'] ?? $last['body']), 0, 8000)).'</div>'
                .'<p style="font-size:.75rem;color:var(--cp-text-dim)">Filter the request ID in Logs Explorer to trace this call.</p>';
        } else {
            $result = '<div class="cp-empty"><div class="cp-empty__icon">→</div>'
                .'<div class="cp-empty__title">No request sent yet</div>'
                .'<div class="cp-empty__hint">Build a request with <strong>Send request</strong> — method, URL, JSON body and key — the response lands here.</div></div>';
        }

        return $schema->components([
            Section::make(__('labels.api_overview'))->schema([
                Html::make('<dl class="cp-kv">'
                    .'<dt>'.e(__('labels.api_base_url')).'</dt><dd><code dir="ltr">'.e($p->api_domain ? 'https://'.$p->api_domain : '—').'</code></dd>'
                    .'<dt>'.e(__('labels.api_health')).'</dt><dd><code dir="ltr">/api/health</code></dd>'
                    .'<dt>'.e(__('labels.api_version')).'</dt><dd>'.e($p->api_version ?? __('labels.api_version_default')).'</dd>'
                    .'<dt>'.e(__('labels.api_snapshot')).'</dt><dd>'.e($snapshot?->captured_at?->locale(app()->getLocale())->translatedFormat('M j, H:i') ?? __('labels.crep_never_scanned'))
                    .' · '.e(__('labels.api_routes_count', ['count' => count($routes)])).' · <a href="'.e(route('control-plane.openapi', ['project' => $p->id])).'" dir="ltr">openapi.json ↓</a></dd>'
                    .'<dt>'.e(__('labels.api_auth_strategy')).'</dt><dd>'.e(__('labels.api_auth_strategy_body')).'</dd>'
                    .'</dl>'),
            ])->compact(),
            Section::make(__('labels.api_endpoints', ['count' => count($routes)]))->schema([Html::make($explorer)])->compact(),
            Section::make(__('labels.api_builder'))->schema([
                Html::make('<p style="font-size:.75rem;color:var(--cp-text-dim)">'.e(__('labels.api_builder_note')).' '
                    .e(__('labels.api_allowed_hosts', ['hosts' => e(implode(', ', ApiStudioService::allowedHosts($p)))]))
                    .' '.e(__('labels.api_limits_note')).'</p>'.$result),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $actions = [];
        if (CpAccess::allows(auth()->user(), 'settings.manage')) {
            $actions[] = Action::make('refresh_snapshot')->label(__('labels.refresh_snapshot'))
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $routes = ApiStudioService::refreshSnapshot($this->project());
                    $this->audit('API_SNAPSHOT_REFRESHED', null, null, ['routes' => count($routes)]);
                    Notification::make()->title(count($routes).' routes captured')->success()->send();
                    $this->redirect(static::getUrl(['record' => $this->project()]));
                });
        }
        $actions[] = Action::make('send_request')->label(__('labels.send_request'))
            ->slideOver()
            ->schema([
                Select::make('method')->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'])
                    ->default('GET')->required(),
                TextInput::make('url')->label('URL')->required()
                    ->placeholder('https://console.test/f/'.$this->project()->slug.'/hello-platform'),
                Textarea::make('body_json')->label(__('labels.json_body'))->rows(3)
                    ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false']),
                TextInput::make('credential')->label(__('labels.api_key_if_required'))->password()->revealable(),
            ])
            ->action(function (array $data) {
                $body = trim((string) ($data['body_json'] ?? ''));
                $decoded = $body === '' ? [] : json_decode($body, true);
                abort_if($body !== '' && ! is_array($decoded), 422, 'Body must be valid JSON.');
                $requestId = (string) \Illuminate\Support\Str::uuid();
                $result = ApiStudioService::send(
                    $this->project(), $data['method'], $data['url'], [], $decoded,
                    $data['credential'] ?: null, $requestId
                );
                $result['body'] = \App\Services\ControlPlane\SecretService::redact($this->project(), $result['body']);
                $result['request_id'] = $requestId;
                session()->flash('cp_api_result', $result);
                $this->audit('FUNCTION_INVOKED', 'api', null, ['url' => $data['url'], 'status' => $result['status'], 'via' => 'api-tester', 'request_id' => $requestId]);
                $note = Notification::make()->title('HTTP '.$result['status'].' in '.$result['duration_ms'].' ms');
                $result['status'] > 0 && $result['status'] < 400 ? $note->success()->send() : $note->danger()->send();
                $this->redirect(static::getUrl(['record' => $this->project()]));
            });

        return $actions;
    }
}
