<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Support\Facades\Http;

/**
 * Phase 24I — Generated Client Setup. Environment-specific SAFE config:
 * public endpoints and project identifiers only — never server secrets.
 * Snippets use the real Phase 22 SDK surface (BackendClient.auth/storage/
 * functions/realtime), never invented method names.
 */
class ClientSetupService
{
    /** Environment-specific safe config (24I.2). */
    public static function configFor(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $apiUrl = $environment?->api_base_url ?: ($project->api_domain ? 'https://'.$project->api_domain : null);
        try {
            $consoleUrl = rtrim(request()->getSchemeAndHttpHost() ?: (string) config('app.url'), '/');
        } catch (\Throwable) {
            $consoleUrl = rtrim((string) config('app.url'), '/');
        }
        $reverb = ReverbStatusService::for($project)->status();
        $wsUrl = ($reverb['host'] && $reverb['port'])
            ? (($reverb['scheme'] === 'https' ? 'wss' : 'ws').'://'.$reverb['host'].':'.$reverb['port'].'/app/YOUR_REVERB_APP_KEY?protocol=7')
            : null;

        return [
            'api_url' => $apiUrl,
            'functions_base_url' => $consoleUrl.'/f/'.$project->slug,
            'realtime_ws_url' => $wsUrl,
            'project_slug' => $project->slug,
            'environment' => $environment?->slug ?? 'development',
            'storage_endpoint' => $apiUrl ? rtrim($apiUrl, '/').'/api' : null,
        ];
    }

    /** Copy-paste setup per client (24I.3) — actual Phase 22 SDK APIs. */
    public static function snippets(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $c = self::configFor($project, $environment);
        $api = $c['api_url'] ?? '(set api_base_url first)';
        $functions = $c['functions_base_url'];

        return [
            'JavaScript' => "import { BackendClient } from '@platform/backend-sdk';\n\n"
                ."const client = new BackendClient({\n"
                ."  baseUrl: '{$api}',\n"
                ."  apiKey: 'PUBLIC_KEY', // CLIENT-SAFE key only — never a server secret\n"
                ."  projectSlug: '{$c['project_slug']}',\n"
                ."  functionsBaseUrl: '{$functions}',\n"
                ."});\n\n"
                ."await client.auth.login({ email: 'you@example.com', password: '…' });\n"
                ."const me = await client.auth.me();\n"
                ."await client.functions.invoke('hello-platform', { body: { name: me.name } });\n"
                ."await client.storage.upload(file, { bucket: 'uploads' });",
            'React / Next.js' => "import { getBrowserClient } from './lib/backend';\n\n"
                ."const backend = getBrowserClient(); // NEXT_PUBLIC_API_URL + BrowserTokenStore\n"
                ."await backend.auth.login({ email, password });\n"
                ."const me = await backend.auth.me();",
            'Flutter / Dart' => "final client = BackendClient(\n"
                ."  baseUrl: '{$api}',\n"
                ."  apiKey: 'PUBLIC_KEY', // CLIENT-SAFE key only\n"
                ."  projectSlug: '{$c['project_slug']}',\n"
                ."  functionsBaseUrl: '{$functions}',\n"
                .");\n\n"
                ."await client.auth.login(email: email, password: password);\n"
                ."final me = await client.auth.me();",
            'PHP (server-to-server)' => "\$backend = new Platform\\BackendSdk\\BackendClient(\n"
                ."    '{$api}',\n"
                ."    getenv('API_SECRET_KEY'), // SERVER-ONLY key — never bundled in clients\n"
                ."    '{$c['project_slug']}',\n"
                ."    '{$functions}',\n"
                .");\n\n"
                ."\$res = \$backend->functions()->invoke('hello-platform', ['name' => 'Ada']);",
        ];
    }

    /** Env file generation (24I.4) — public values only. */
    public static function envFiles(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $c = self::configFor($project, $environment);
        $api = $c['api_url'] ?? '';

        return [
            '.env.example (JS/PHP)' => "API_URL={$api}\n"
                ."PUBLIC_KEY=cp_…          # CLIENT-SAFE scopes only (read:data, storage:read, functions:invoke)\n"
                ."API_SECRET_KEY=           # SERVER-ONLY — never ships to browsers/apps\n"
                ."FUNCTIONS_BASE_URL={$c['functions_base_url']}\n"
                ."PROJECT_SLUG={$c['project_slug']}\n"
                ."REALTIME_WS_URL=".($c['realtime_ws_url'] ?? ''),
            'dart-define' => "--dart-define=API_URL={$api}\n"
                ."--dart-define=PUBLIC_KEY=cp_…   # CLIENT-SAFE key only\n"
                ."--dart-define=PROJECT_SLUG={$c['project_slug']}",
            'Next.js public env' => "NEXT_PUBLIC_API_URL={$api}\n"
                ."NEXT_PUBLIC_PROJECT_SLUG={$c['project_slug']}\n"
                ."# NEXT_PUBLIC_* is CLIENT-SAFE by contract — never place server secrets here",
        ];
    }

    /**
     * Connection test (24I.5) — safe calls only, SSRF-guarded: only the
     * project's own api_domain / environment api_base_url is contacted.
     */
    public static function testConnection(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $c = self::configFor($project, $environment);
        $results = [];
        $api = $c['api_url'];

        if (! $api) {
            AdminAudit::record('CLIENT_CONNECTION_TESTED', $project, 'environment', $environment?->id, ['result' => 'no-api-url']);

            return ['overall' => 'unavailable', 'checks' => [['target' => 'api', 'status' => 'unavailable', 'detail' => 'api_base_url/api_domain not set']]];
        }

        $allowedHost = strtolower((string) parse_url($api, PHP_URL_HOST));
        $check = function (string $url, string $target) use ($allowedHost, $project, $environment) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true)) {
                return ['target' => $target, 'status' => 'blocked', 'detail' => 'only http(s) test targets allowed'];
            }
            if ($host !== $allowedHost || self::isInternalHost($host)) {
                return ['target' => $target, 'status' => 'blocked', 'detail' => 'host outside project allowlist (SSRF guard)'];
            }
            try {
                $response = Http::timeout(6)->get($url);
                return ['target' => $target, 'status' => $response->successful() ? 'ok' : 'error', 'http_status' => $response->status()];
            } catch (\Throwable $e) {
                return ['target' => $target, 'status' => 'error', 'detail' => \Illuminate\Support\Str::limit($e->getMessage(), 120)];
            }
        };

        $results[] = $check(rtrim($api, '/').'/api/health', 'api_health');
        // Auth bootstrap capability: /api/health reports db+redis; a /api/v1/user
        // unauthenticated call must return 401, proving auth wiring.
        try {
            $response = Http::timeout(6)->get(rtrim($api, '/').'/api/v1/user');
            $results[] = ['target' => 'auth_bootstrap', 'status' => $response->status() === 401 ? 'ok' : 'warning', 'http_status' => $response->status(), 'detail' => 'unauthenticated /user returned '.$response->status()];
        } catch (\Throwable $e) {
            $results[] = ['target' => 'auth_bootstrap', 'status' => 'error', 'detail' => \Illuminate\Support\Str::limit($e->getMessage(), 120)];
        }
        if ($c['realtime_ws_url']) {
            $results[] = ['target' => 'realtime', 'status' => 'ok', 'detail' => 'endpoint configured: '.$c['realtime_ws_url']];
        } else {
            $results[] = ['target' => 'realtime', 'status' => 'unavailable', 'detail' => 'realtime not configured'];
        }

        AdminAudit::record('CLIENT_CONNECTION_TESTED', $project, 'environment', $environment?->id, [
            'result' => collect($results)->every(fn ($r) => $r['status'] === 'ok') ? 'ok' : 'degraded',
        ]);

        return [
            'overall' => collect($results)->every(fn ($r) => in_array($r['status'], ['ok'], true)) ? 'ok' : 'degraded',
            'checks' => $results,
        ];
    }

    /** Cloud/metadata/internal targets must never be probed by the tester. */
    protected static function isInternalHost(string $host): bool
    {
        if ($host === '' || in_array($host, ['localhost', 'metadata.google.internal', 'metadata.goog'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }
}
