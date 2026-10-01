<?php

namespace App\Connectors\Firebase\Protocol;

use Illuminate\Support\Facades\Http;

/**
 * Phase 29A — production HTTP transport (Laravel HTTP client).
 *
 * Bearer authorization is injected by the caller when a token provider is
 * configured; the emulator mode runs unauthenticated. Every request passes
 * through ConnectorNetworkGuard SSRF protection at the URL level.
 */
class HttpFirebaseTransport implements FirebaseTransport
{
    /** @var callable():string|null returns a fresh bearer token */
    protected $tokenProvider;

    public function __construct(
        ?callable $tokenProvider = null,
        protected int $timeoutSeconds = 30,
    ) {
        $this->tokenProvider = $tokenProvider;
    }

    public function send(string $method, string $url, array $options = []): array
    {
        $request = Http::timeout($this->timeoutSeconds)
            ->withHeaders($options['headers'] ?? []);
        if (($token = $this->currentToken()) !== null) {
            $request = $request->withToken($token);
        }
        if (isset($options['query'])) {
            $request = $request->withOptions(['query' => $options['query']]);
        }
        if (array_key_exists('json', $options)) {
            $request = $request->acceptJson();
        }

        try {
            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $options['query'] ?? []),
                'POST' => $request->post($url, $options['json'] ?? []),
                default => throw new FirebaseTransportException("unsupported method {$method}"),
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new FirebaseTransportException('connection to Firebase API failed: '.mb_substr($e->getMessage(), 0, 160), 0);
        }

        $status = $response->status();
        if ($status >= 400) {
            throw new FirebaseTransportException("Firebase API error (HTTP {$status}) for ".self::safeUrl($url), $status);
        }

        return ['status' => $status, 'body' => $response->body()];
    }

    protected function currentToken(): ?string
    {
        return ($this->tokenProvider !== null) ? (string) ($this->tokenProvider)() : null;
    }

    /** URL form safe for error surfaces: query string (can carry tokens) stripped. */
    public static function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
    }
}
