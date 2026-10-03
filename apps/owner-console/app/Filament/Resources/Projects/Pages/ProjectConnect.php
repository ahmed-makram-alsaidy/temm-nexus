<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Pages\Concerns\HasProjectContext;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ProjectApiKey;
use App\Services\ControlPlane\CpAccess;
use App\Services\ControlPlane\ReverbStatusService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Phase 22G/22O project Connect page: the safe "connect a client" experience.
 *
 * Shows API URL, public key PREFIXES (never secret material), SDK install
 * commands, copyable snippets (JavaScript / React+Next.js / Flutter / PHP /
 * cURL) and env examples. Raw server secrets are never rendered here — key
 * creation/reveal stays on the API Keys page (single-use reveal).
 */
class ProjectConnect extends Page
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
        return __('labels.connect');
    }

    public function getBreadcrumbs(): array
    {
        return ['Connect'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return CpAccess::allows(auth()->user(), 'projects.view');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'cp-reference cp-reference--tool'])->components([$this->subnavSection('connect'), EmbeddedSchema::make('infolist')]);
    }

    public function infolist(Schema $schema): Schema
    {
        $p = $this->project();
        // Phase 24I: environment-aware generated client setup.
        $env = \App\Services\ControlPlane\EnvironmentContext::active($p);
        $config = \App\Services\ControlPlane\ClientSetupService::configFor($p, $env);
        $apiUrl = $config['api_url'] ?? '(set api_domain / environment api_base_url first)';
        $apiVersion = $p->api_version ?? 'v1';
        // Console base: the host that actually served this page (correct behind
        // Caddy), falling back to APP_URL for CLI/test contexts. Proven live
        // 22.1: config APP_URL is a container-local default (localhost:8000).
        try {
            $consoleUrl = rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url'), '/');
        } catch (\Throwable) {
            $consoleUrl = rtrim((string) config('app.url'), '/');
        }
        $functionsUrl = $consoleUrl.'/f/'.$p->slug;
        $wsUrl = $config['realtime_ws_url'] ?? '(configure Reverb in the project .env first)';

        $keys = ProjectApiKey::query()->where('project_id', $p->id)->orderByDesc('id')->get();
        $keyRows = '';
        foreach ($keys as $k) {
            $state = $k->revoked_at
                ? '<span class="cp-badge is-danger">revoked</span>'
                : (($k->expires_at && $k->expires_at->isPast())
                    ? '<span class="cp-badge is-warning">expired</span>'
                    : '<span class="cp-badge is-success">active</span>');
            $keyRows .= '<tr><td>'.e($k->name).'</td><td><code>'.e($k->prefix).'…</code></td>'
                .'<td>'.e(implode(', ', $k->scopes ?? [])).'</td><td>'.$state.'</td></tr>';
        }
        if ($keyRows === '') {
            $keysUrl = ProjectApiKeys::getUrl(['record' => $p]);
            $keyRows = '<tr><td colspan="4">No API keys yet — <a href="'.e($keysUrl).'">create one on the API Keys page</a> (shown once, then only prefixes here).</td></tr>';
        }

        $snippets = [
            'JavaScript' => [
                'install' => 'npm install @platform/backend-sdk   # Phase 22: private — file: link until published',
                'code' => "import { BackendClient } from '@platform/backend-sdk';\n\n"
                    ."const client = new BackendClient({\n"
                    ."  baseUrl: '{$apiUrl}',\n"
                    ."  apiKey: 'PUBLIC_KEY', // CLIENT-SAFE key only — never a server secret\n"
                    ."  projectSlug: '{$p->slug}',\n"
                    ."  functionsBaseUrl: '{$consoleUrl}',\n"
                    ."});\n\n"
                    ."await client.auth.login({ email: 'ada@example.com', password: '…' });\n"
                    ."const me = await client.auth.me();\n"
                    ."await client.functions.invoke('hello-platform', { body: { name: me.name } });\n"
                    ."await client.auth.logout();",
            ],
            'React / Next.js' => [
                'install' => 'npm install @platform/backend-sdk   # + copy examples/react-next-starter/lib/backend.ts',
                'code' => "import { getBrowserClient } from './lib/backend';\n\n"
                    ."// Client Component\n"
                    ."const backend = getBrowserClient(); // NEXT_PUBLIC_API_URL + BrowserTokenStore\n"
                    ."await backend.auth.login({ email, password });\n"
                    ."await backend.auth.me();\n\n"
                    ."// Server Component (per request — never a global token):\n"
                    ."import { getServerClient } from './lib/backend';\n"
                    ."const server = getServerClient(tokenFromCookie);\n"
                    ."const me = await server.auth.me();",
            ],
            'Flutter' => [
                'install' => "dependencies:\n  backend_sdk:\n    path: ../../packages/backend_sdk_dart   # Phase 22: path/git until published",
                'code' => "final client = BackendClient(\n"
                    ."  baseUrl: '{$apiUrl}',\n"
                    ."  apiKey: 'PUBLIC_KEY', // CLIENT-SAFE key only\n"
                    ."  projectSlug: '{$p->slug}',\n"
                    ."  functionsBaseUrl: '{$consoleUrl}',\n"
                    .");\n\n"
                    ."await client.auth.login(email: email, password: password);\n"
                    ."final me = await client.auth.me();\n"
                    ."await client.functions.invoke('hello-platform', body: {'name': me.name});\n"
                    ."await client.auth.logout(); // production: SecureTokenStoreAdapter + flutter_secure_storage",
            ],
            'PHP (server-to-server)' => [
                'install' => '"repositories": [{"type": "path", "url": "../../packages/backend-sdk-php"}]   # until Packagist',
                'code' => "\$backend = new Platform\\BackendSdk\\BackendClient(\n"
                    ."    '{$apiUrl}',\n"
                    ."    getenv('API_SECRET_KEY'), // SERVER-ONLY key — never in git, never in the browser\n"
                    ."    '{$p->slug}',\n"
                    ."    '{$consoleUrl}',\n"
                    .");\n\n"
                    ."\$res = \$backend->functions()->invoke('hello-platform', ['name' => 'Ada']);\n"
                    ."\$url = \$backend->storage()->signedUrl('invoices', '2026/09/inv-1.pdf');",
            ],
            'cURL' => [
                'install' => '# no install — any shell with curl',
                'code' => "# Login (user token)\n"
                    ."curl -s -X POST {$apiUrl}/api/v1/auth/login \\\n"
                    ."  -H 'Content-Type: application/json' \\\n"
                    ."  -d '{\"email\":\"ada@example.com\",\"password\":\"…\"}'\n\n"
                    ."# Me\n"
                    ."curl -s {$apiUrl}/api/v1/user -H 'Authorization: Bearer TOKEN_ID|TOKEN'\n\n"
                    ."# Function (API key, scope functions:invoke)\n"
                    ."curl -s -X POST {$functionsUrl}/hello-platform \\\n"
                    ."  -H 'Authorization: Bearer PUBLIC_KEY' -H 'X-Request-ID: demo-1' \\\n"
                    ."  -H 'Content-Type: application/json' -d '{\"name\":\"Ada\"}'\n\n"
                    ."# Realtime endpoint (Pusher protocol, ws)\n"
                    ."# {$wsUrl}",
            ],
        ];

        $tabs = '';
        $first = true;
        foreach ($snippets as $label => $s) {
            $codeId = 'cp-connect-'.md5($label);
            $tabs .= '<details class="cp-details"'.($first ? ' open' : '').'><summary>'.e($label).'</summary>'
                .'<h4 style="margin:.5rem 0 .25rem">Install</h4><div class="cp-code">'.e($s['install']).'</div>'
                .'<h4 style="margin:.75rem 0 .25rem">Connect <button type="button" class="cp-btn" data-cp-copy="'.$codeId.'">Copy</button></h4>'
                .'<div class="cp-code"><pre id="'.$codeId.'" style="margin:0;white-space:pre-wrap">'.e($s['code']).'</pre></div>'
                .'</details>';
            $first = false;
        }

        $envExample = "API_URL={$apiUrl}\n"
            ."PUBLIC_KEY=cp_…          # CLIENT-SAFE scopes only (read:data, storage:read, functions:invoke)\n"
            ."API_SECRET_KEY=           # SERVER-ONLY — never ships to browsers/apps (write:data, storage:write)\n"
            ."FUNCTIONS_BASE_URL={$consoleUrl}\n"
            ."PROJECT_SLUG={$p->slug}\n"
            ."REALTIME_WS_URL={$wsUrl}";

        $copyScript = '<script>(function(){document.querySelectorAll("[data-cp-copy]").forEach(function(b){b.addEventListener("click",function(){var el=document.getElementById(b.getAttribute("data-cp-copy"));if(!el)return;navigator.clipboard.writeText(el.innerText).then(function(){b.innerText="Copied";setTimeout(function(){b.innerText="Copy"},1500)});});});})();</script>';

        $testResults = (string) session("cp_connect_test_{$p->id}");
        $testBlock = $testResults !== ''
            ? '<div class="cp-tablewrap" style="margin-top:.5rem"><table class="cp-grid"><thead><tr><th>'.e(__('labels.target')).'</th><th>'.e(__('labels.status')).'</th><th>'.e(__('labels.detail')).'</th></tr></thead><tbody>'.$testResults.'</tbody></table></div>'
            : '';

        return $schema->components([
            Section::make('Connection')->schema([
                Html::make('<dl class="cp-kv">'
                    .'<dt>Environment</dt><dd><code>'.e($env->slug).'</code></dd>'
                    .'<dt>API URL</dt><dd><code>'.e($apiUrl).'</code></dd>'
                    .'<dt>Health</dt><dd><code>'.e($apiUrl.'/api/health').'</code></dd>'
                    .'<dt>Version</dt><dd>'.e($apiVersion).'</dd>'
                    .'<dt>Functions</dt><dd><code>'.e($functionsUrl.'/{function}').'</code></dd>'
                    .'<dt>Realtime</dt><dd><code>'.e($wsUrl).'</code></dd>'
                    .'</dl><p style="font-size:.75rem;color:var(--cp-text-dim)">Full wire contract: <code>docs/CLIENT_API_CONTRACT.md</code>. '
                    .'Raw secrets are never shown here — create keys on the API Keys page (shown once), then paste the value into your env.</p>'
                    .$testBlock),
            ])->compact(),
            Section::make('API keys (prefixes only)')->schema([
                Html::make('<div class="cp-tablewrap"><table class="cp-grid"><thead><tr>'
                    .'<th>'.e(__('labels.erd_name')).'</th><th>'.e(__('labels.th_prefix')).'</th><th>'.e(__('labels.th_scopes')).'</th><th>'.e(__('labels.status')).'</th>'
                    .'</tr></thead><tbody>'.$keyRows.'</tbody></table></div>'
                    .'<p style="font-size:.75rem;color:var(--cp-text-dim)"><strong>CLIENT SAFE</strong> (may ship in web/Flutter builds): '
                    .'minimum scopes the screen needs — typically <code>read:data</code>, <code>storage:read</code>, '
                    .'<code>functions:invoke</code> on low-risk functions. <strong>SERVER ONLY</strong> (never bundled): '
                    .'<code>write:data</code>, <code>storage:write</code>, privileged invokes — PHP SDK / automation only. '
                    .'See <code>docs/client-sdk/SECURITY.md</code>.</p>'),
            ])->compact(),
            Section::make('SDKs — pick your platform ('.count($snippets).')')->schema([
                Html::make($tabs.$copyScript),
            ])->compact(),
            Section::make('Environment example')->schema([
                Html::make('<div class="cp-code"><pre id="cp-connect-env" style="margin:0;white-space:pre-wrap">'.e($envExample).'</pre></div>'
                    .'<p style="margin-top:.5rem"><button type="button" class="cp-btn" data-cp-copy="cp-connect-env">Copy</button></p>'),
            ])->compact(),
            $this->generatedConfigSection($p, $env),
        ]);
    }

    /** Phase 24I — generated per-environment safe config + connection test UI. */
    protected function generatedConfigSection($p, $env): Section
    {
        $envFiles = \App\Services\ControlPlane\ClientSetupService::envFiles($p, $env);
        $envTabs = '';
        $first = true;
        foreach ($envFiles as $label => $content) {
            $id = 'cp-envgen-'.md5($label);
            $envTabs .= '<details class="cp-details"'.($first ? ' open' : '').'><summary>'.e($label).'</summary>'
                .'<div class="cp-code"><pre id="'.$id.'" style="margin:0;white-space:pre-wrap">'.e($content).'</pre></div>'
                .'<p><button type="button" class="cp-btn" data-cp-copy="'.$id.'">Copy</button></p></details>';
            $first = false;
        }

        return Section::make('Generated client setup — environment: '.e($env->slug))->schema([
            Html::make(
                '<p style="font-size:.75rem;color:var(--cp-text-dim)">Environment-specific SAFE config: public endpoints and identifiers only — '
                .'server secrets never appear here or in generated env files. Snippets use the real Phase 22 SDK surface.</p>'
                .$envTabs
            ),
        ])->compact();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')->label(__('labels.test_connection'))->icon('heroicon-o-signal')
                ->action(function () {
                    $p = $this->project();
                    $env = \App\Services\ControlPlane\EnvironmentContext::active($p);
                    $result = \App\Services\ControlPlane\ClientSetupService::testConnection($p, $env);
                    $rows = '';
                    foreach ($result['checks'] as $check) {
                        $badge = match ($check['status']) {
                            'ok' => 'is-success', 'blocked' => 'is-danger', 'error' => 'is-danger', 'unavailable' => '', default => 'is-warning',
                        };
                        $rows .= '<tr><td><code>'.e($check['target']).'</code></td><td><span class="cp-badge '.$badge.'">'.e($check['status']).'</span></td>'
                            .'<td style="font-size:.75rem">'.e((string) ($check['detail'] ?? $check['http_status'] ?? '')).'</td></tr>';
                    }
                    Notification::make()->title(__('labels.connection_test_frag').$result['overall'])
                        ->success($result['overall'] === 'ok')->warning($result['overall'] !== 'ok')->send();
                    session(["cp_connect_test_{$p->id}" => $rows]);
                    $this->redirect(static::getUrl(['record' => $p]));
                }),
        ];
    }
}
