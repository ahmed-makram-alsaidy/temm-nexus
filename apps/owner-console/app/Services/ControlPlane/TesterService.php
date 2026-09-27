<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\Http;

/**
 * Phase 20H function tester transport: performs a REAL HTTP request through
 * the Caddy reverse proxy (same path a client takes), so status, headers,
 * body and duration are genuinely observed — not simulated.
 */
class TesterService
{
    public static function invokeUrl(string $projectSlug, string $functionSlug): string
    {
        return route('control-plane.function-invoke', [$projectSlug, $functionSlug], false);
    }

    /**
     * @return array{status:int,headers:array,body:string,duration_ms:int,error:?string,request_id:string}
     */
    public static function httpInvoke(
        string $projectSlug, string $functionSlug, string $method = 'GET',
        array $query = [], array $headers = [], mixed $body = null,
        int $timeout = 15, ?string $requestId = null
    ): array {
        $path = self::invokeUrl($projectSlug, $functionSlug);
        if ($query) {
            $path .= '?'.http_build_query($query);
        }
        // Same edge the outside world uses; Host header selects the vhost.
        $url = 'http://caddy:80'.$path;
        $requestId ??= (string) \Illuminate\Support\Str::uuid();
        $headers = array_merge(['Host' => 'console.test', 'Accept' => 'application/json', 'X-Request-ID' => $requestId], $headers);

        $started = microtime(true);
        try {
            $pending = Http::timeout($timeout)->withoutRedirecting()->withHeaders($headers);
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $body ?? []),
                'PUT' => $pending->put($url, $body ?? []),
                'PATCH' => $pending->patch($url, $body ?? []),
                'DELETE' => $pending->delete($url),
                default => abort(422, 'Unsupported method.'),
            };
        } catch (\Throwable $e) {
            return [
                'status' => 0, 'headers' => [], 'body' => '',
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'error' => mb_substr($e->getMessage(), 0, 300),
                'request_id' => $requestId,
            ];
        }

        // Prefer the server-echoed ID (proves end-to-end propagation).
        $echoed = $response->header('X-Request-ID');

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => mb_substr($response->body(), 0, 20000),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'error' => null,
            'request_id' => $echoed ?: $requestId,
        ];
    }
}
