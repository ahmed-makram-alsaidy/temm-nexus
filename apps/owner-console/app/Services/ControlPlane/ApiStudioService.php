<?php

namespace App\Services\ControlPlane;

use App\Models\ApiRouteSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

/**
 * Phase 20I API Studio backend: snapshot refresh from the project checkout,
 * SSRF-guarded request tester, OpenAPI generation.
 */
class ApiStudioService
{
    /** Hosts the tester may call (project's own API + this console). */
    public static function allowedHosts(Project $project): array
    {
        $hosts = ['console.test', 'caddy'];
        if ($project->api_domain) {
            $hosts[] = strtolower($project->api_domain);
        }

        return $hosts;
    }

    public static function refreshSnapshot(Project $project): array
    {
        $result = ProjectArtisan::run($project, 'route:list', ['--json']);
        abort_unless($result['ok'], 422, 'route:list failed in project checkout.');
        $decoded = json_decode($result['output'], true);
        abort_unless(is_array($decoded), 422, 'Could not parse route list.');
        $routes = [];
        foreach ($decoded as $r) {
            $uri = $r['uri'] ?? '';
            if (! str_starts_with($uri, 'api/') && $uri !== 'up') {
                continue;
            }
            if (str_contains($uri, 'admin') || str_contains($uri, 'horizon') || str_contains($uri, 'pulse') || str_contains($uri, 'sanctum')) {
                continue;
            }
            $routes[] = [
                'method' => explode('|', $r['method'] ?? 'GET')[0],
                'uri' => '/'.$uri,
                'name' => $r['name'] ?? null,
                'middleware' => $r['middleware'] ?? [],
                'auth' => str_contains(json_encode($r['middleware'] ?? []), 'auth') ? 'auth' : 'public',
            ];
        }
        ApiRouteSnapshot::updateOrCreate(
            ['project_id' => $project->id],
            ['routes' => $routes, 'captured_at' => now()]
        );

        return $routes;
    }

    /**
     * @return array{status:int,headers:array,body:string,duration_ms:int,error:?string}
     */
    public static function send(
        Project $project, string $method, string $url,
        array $headers = [], mixed $body = null, ?string $credential = null,
        ?string $requestId = null
    ): array {
        $parts = parse_url($url);
        abort_unless($parts && isset($parts['host']), 422, 'Invalid URL.');
        $host = strtolower($parts['host']);
        abort_unless(in_array($host, self::allowedHosts($project), true), 403, 'Host not allowlisted for testing.');
        abort_if(preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host) === 1, 403, 'Raw IP targets are not allowed.');
        $scheme = strtolower($parts['scheme'] ?? '');
        abort_unless(in_array($scheme, ['http', 'https'], true), 422, 'Only http(s) targets.');

        // Route console.test through the local proxy; project domains as-is.
        $target = $host === 'console.test' ? 'http://caddy:80'.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '') : $url;
        $headers = array_merge(['Host' => $host, 'Accept' => 'application/json'], $headers);
        if ($requestId) {
            $headers['X-Request-ID'] = $requestId;
        }
        if ($credential) {
            $headers['Authorization'] = 'Bearer '.$credential;
        }

        $started = microtime(true);
        try {
            $pending = Http::timeout(10)->withoutRedirecting()->withHeaders($headers);
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($target),
                'POST' => $pending->post($target, is_array($body) ? $body : []),
                'PUT' => $pending->put($target, is_array($body) ? $body : []),
                'PATCH' => $pending->patch($target, is_array($body) ? $body : []),
                'DELETE' => $pending->delete($target),
                default => abort(422, 'Unsupported method.'),
            };
        } catch (\Throwable $e) {
            return [
                'status' => 0, 'headers' => [], 'body' => '',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => mb_substr($response->body(), 0, 50000),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'error' => null,
        ];
    }

    public static function openapi(Project $project): array
    {
        $snapshot = ApiRouteSnapshot::query()->where('project_id', $project->id)->first();
        $paths = [];
        foreach ($snapshot->routes ?? [] as $r) {
            $paths[$r['uri']][strtolower($r['method'])] = [
                'summary' => $r['name'] ?? ($r['method'].' '.$r['uri']),
                'security' => $r['auth'] === 'auth' ? [['bearerAuth' => []]] : [],
                'responses' => ['200' => ['description' => 'OK']],
            ];
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $project->name.' API', 'version' => $project->api_version ?? 'v1'],
            'servers' => [['url' => $project->api_domain ? 'https://'.$project->api_domain : 'https://api.'.$project->slug.'.test']],
            'paths' => $paths,
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']]],
        ];
    }
}
