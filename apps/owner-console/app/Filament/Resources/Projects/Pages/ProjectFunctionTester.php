<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\FunctionInvocation;
use App\Models\ProjectFunction;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\SecretService;
use App\Services\ControlPlane\TesterService;
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
 * Phase 20H function tester: method, query, headers, JSON body, auth identity.
 * Executes a REAL HTTP request through Caddy and shows status, headers,
 * body, duration — plus the server-side invocation log row it produced.
 */
class ProjectFunctionTester extends Page
{
    use HasProjectContext;
    use InteractsWithRecord;

    protected static string $resource = ProjectResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public ?int $fnId = null;

    public function mount(int|string $record, int|string $fn): void
    {
        $this->record = $this->resolveRecord($record);
        $this->fnId = (int) $fn;
    }

    public function function(): ProjectFunction
    {
        return ProjectFunction::query()
            ->where('project_id', $this->project()->id)
            ->findOrFail($this->fnId ?? request()->route('fn'));
    }

    public function getTitle(): string|Htmlable
    {
        try {
            return 'Test · '.$this->function()->slug;
        } catch (\Throwable) {
            return __('labels.function_tester');
        }
    }

    public function getBreadcrumbs(): array
    {
        try {
            return [static::projectUrl($this->project(), 'functions') => 'Functions', 'Test · '.$this->function()->slug];
        } catch (\Throwable) {
            return ['Functions'];
        }
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'functions.invoke');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('functions'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $fn = $this->function();
        $last = session('cp_fn_result');
        $result = $last
            ? '<h4 style="margin:.25rem 0">Last response · '.(int) $last['duration_ms'].' ms</h4>'
              .'<p style="font-size:.75rem">Request ID: <code>'.e($last['request_id'] ?? '—').'</code> — filter it in Logs Explorer to trace this call.</p>'
              .'<div class="cp-code">HTTP '.(int) $last['status']."\n\n"
              .e(mb_substr((string) $last['body'], 0, 6000)).'</div>'
            : '<div class="cp-empty"><div class="cp-empty__title">No invocation yet</div>'
              .'<div class="cp-empty__hint">Send a request to see status, headers, body and duration.</div></div>';

        $logs = '';
        foreach (
            FunctionInvocation::query()->where('function_id', $fn->id)
                ->orderByDesc('id')->limit(15)->get() as $inv
        ) {
            $badge = $inv->status < 400 ? 'is-success' : ($inv->status === 429 ? 'is-warning' : 'is-danger');
            $logs .= '<tr><td>'.e($inv->created_at?->format('H:i:s') ?? '—').'</td>'
                .'<td><span class="cp-badge '.$badge.'">'.(int) $inv->status.'</span></td>'
                .'<td>v'.(int) $inv->version.'</td><td>'.(int) $inv->duration_ms.' ms</td>'
                .'<td><code>'.e($inv->request_id).'</code></td><td>'.e($inv->actor).'</td></tr>';
        }

        $editorUrl = ProjectFunctionEditor::getUrl(['record' => $this->project(), 'fn' => $fn->id]);
        $selfUrl = static::getUrl(['record' => $this->project(), 'fn' => $fn->id]);
        $tabs = '<div class="cp-tabs" role="tablist" aria-label="Function views">'
            .'<button type="button" aria-selected="false" onclick="location.href=\''.e($editorUrl).'\'">Editor</button>'
            .'<button type="button" aria-selected="true" onclick="location.href=\''.e($selfUrl).'\'">Test</button>'
            .'<button type="button" aria-selected="false" onclick="location.href=\'#logs\'">Logs</button>'
            .'</div>';

        return $schema->components([
            Html::make($tabs),
            Section::make('Request · '.$fn->slug)->schema([
                Html::make(
                    '<dl class="cp-kv"><dt>Endpoint</dt><dd><code>'
                    .e(TesterService::invokeUrl($this->project()->slug, $fn->slug))
                    .'</code></dd><dt>Auth mode</dt><dd>'.e($fn->auth_mode)
                    .'</dd><dt>Methods</dt><dd>'.e(implode(', ', $fn->methods ?? [])).'</dd></dl>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)">For <code>key</code> mode paste a project API key below; '
                    .'for <code>user</code> mode paste a project user token (<code>id|secret</code>).</p>'
                ),
            ])->compact(),
            Section::make('Response')->schema([Html::make($result)])->compact(),
            Section::make('Recent invocations')->schema([
                Html::make('<div id="logs"></div><div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>Time</th><th>Status</th><th>Version</th><th>Duration</th><th>Request ID</th><th>Actor</th>'
                    .'</tr></thead><tbody>'.($logs ?: '<tr><td colspan="6"><div class="cp-empty"><div class="cp-empty__icon">◷</div><div class="cp-empty__title">No invocations yet</div><div class="cp-empty__hint">Send a request above — every call lands here with status, version and request ID.</div></div></td></tr>').'</tbody></table></div>'),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label(__('labels.send_request'))
                ->schema([
                    Select::make('method')->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE'])
                        ->default('GET')->required(),
                    TextInput::make('query')->label(__('labels.query_string'))->placeholder('key=1&debug=true'),
                    Textarea::make('body_json')->label(__('labels.json_body_for_post_put_patch'))->rows(4)
                        ->extraAttributes(['class' => 'cp-code', 'spellcheck' => 'false']),
                    TextInput::make('credential')->label(__('labels.api_key_user_token_as_required_by_auth_m'))
                        ->password()->revealable(),
                ])
                ->action(function (array $data) {
                    CpAccess::require(auth()->user(), 'functions.invoke');
                    $fn = $this->function();
                    parse_str((string) ($data['query'] ?? ''), $query);
                    $body = trim((string) ($data['body_json'] ?? ''));
                    $decoded = $body === '' ? [] : json_decode($body, true);
                    abort_if($body !== '' && ! is_array($decoded), 422, 'Body must be valid JSON.');
                    $headers = [];
                    if (! empty($data['credential'])) {
                        $headers['Authorization'] = 'Bearer '.$data['credential'];
                    }
                    $requestId = (string) \Illuminate\Support\Str::uuid();
                    $result = TesterService::httpInvoke(
                        $this->project()->slug, $fn->slug,
                        $data['method'] ?? 'GET', $query, $headers, $decoded,
                        15, $requestId
                    );
                    // Redact any pasted secret echoes (defense in depth for display).
                    $result['body'] = SecretService::redact($this->project(), $result['body']);
                    session()->flash('cp_fn_result', $result);
                    $this->audit('FUNCTION_INVOKED', 'function', $fn->id, [
                        'slug' => $fn->slug, 'status' => $result['status'], 'via' => 'tester',
                        'request_id' => $result['request_id'] ?? $requestId,
                    ]);
                    $note = Notification::make()->title('HTTP '.$result['status'].' in '.$result['duration_ms'].' ms');
                    $result['status'] < 400 ? $note->success()->send() : $note->danger()->send();
                    $this->redirect(static::getUrl(['record' => $this->project(), 'fn' => $fn->id]));
                }),
        ];
    }
}
