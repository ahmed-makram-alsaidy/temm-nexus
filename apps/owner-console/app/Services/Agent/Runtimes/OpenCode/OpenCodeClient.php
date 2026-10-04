<?php

namespace App\Services\Agent\Runtimes\OpenCode;

use App\Services\Agent\Contract\AgentRuntimeException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * HTTP transport for the OpenCode server API (verified against the official
 * OpenAPI schema served by the runtime at GET /doc — see
 * docs/agent-runtime/OPENCODE_INTEGRATION.md).
 *
 * Responsibilities and NON-responsibilities:
 *  - Translates transport failures into structured AgentRuntimeException
 *    categories (RUNTIME_UNAVAILABLE / RUNTIME_AUTH_FAILED / TIMEOUT /
 *    INVALID_RUNTIME_RESPONSE). It never leaks auth material into messages.
 *  - Validates response SEMANTICS: an HTTP 200 with a body that is not the
 *    expected shape is an INVALID_RUNTIME_RESPONSE, never a silent success.
 *  - Owns SSE line framing for the official `GET /event` stream, with an
 *    idle timeout so a stalled runtime cannot wedge a worker forever.
 */
class OpenCodeClient
{
    protected PendingRequest $http;

    public function __construct(
        protected string $baseUrl,
        protected ?string $username = null,
        protected ?string $password = null,
        protected int $connectTimeoutSeconds = 10,
        protected int $requestTimeoutSeconds = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = Http::baseUrl($this->baseUrl)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->requestTimeoutSeconds)
            ->acceptJson()
            ->when($this->password !== null && $this->password !== '', fn (PendingRequest $r) => $r->withBasicAuth($this->username ?? 'opencode', $this->password));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // ── App / discovery ─────────────────────────────────────────────────

    /** @return array{healthy: bool, version: string} */
    public function health(): array
    {
        $json = $this->getJson('/global/health');

        if (! is_array($json) || ! array_key_exists('healthy', $json) || ! array_key_exists('version', $json)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime health response did not have the expected shape.'
            );
        }

        return ['healthy' => (bool) $json['healthy'], 'version' => (string) $json['version']];
    }

    /** @return list<array{id: string, name: string, models: array<string, mixed>}> */
    public function providers(): array
    {
        $json = $this->getJson('/config/providers');

        $providers = $json['providers'] ?? null;
        if (! is_array($providers)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime provider listing did not have the expected shape.'
            );
        }

        return $providers;
    }

    // ── Sessions ────────────────────────────────────────────────────────

    /**
     * Create a session bound to the given directory (official `?directory=`
     * instance selection). Returns the raw session payload.
     *
     * @param  array<string, mixed>  $permission
     * @return array<string, mixed>
     */
    public function createSession(string $directory, ?string $model, array $permission, ?string $title = null): array
    {
        [$providerId, $modelId] = self::splitModel($model);

        $body = array_filter([
            'title' => $title,
            'model' => ($providerId !== null && $modelId !== null)
                ? ['providerID' => $providerId, 'id' => $modelId]
                : null,
            'permission' => $permission,
        ], fn ($v) => $v !== null);

        $session = $this->postJson('/session', $body, ['directory' => $directory]);

        if (! is_array($session) || empty($session['id']) || ! is_string($session['id'])) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime session creation did not return a session id.'
            );
        }

        // Trust but verify: the session MUST be bound to the directory we
        // asked for. A runtime that silently binds elsewhere is a hard error,
        // never a task running in the wrong workspace.
        $boundDirectory = (string) ($session['directory'] ?? '');
        if ($boundDirectory !== '' && ! self::sameDirectory($boundDirectory, $directory)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::WORKSPACE_FAILED,
                'Runtime bound the session to an unexpected directory.'
            );
        }

        return $session;
    }

    /** @param  array<string, mixed>  $permission */
    public function sendPromptAsync(string $sessionId, string $prompt, ?string $model = null, array $permission = []): void
    {
        [$providerId, $modelId] = self::splitModel($model);

        // 1.18.34 shape: the prompt travels as a parts array (TextPartInput).
        $body = array_filter([
            'parts' => [['type' => 'text', 'text' => $prompt]],
            'model' => ($providerId !== null && $modelId !== null)
                ? ['providerID' => $providerId, 'modelID' => $modelId]
                : null,
        ], fn ($v) => $v !== null);

        $this->post("/session/{$sessionId}/prompt_async", $body, [], 204);
    }

    /** @return list<array{path: string, status: string, additions: int, deletions: int, patch: string}> */
    public function sessionDiff(string $sessionId): array
    {
        $json = $this->getJson("/session/{$sessionId}/diff");

        if (! is_array($json)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime diff response did not have the expected shape.'
            );
        }

        $diffs = [];
        foreach ($json as $entry) {
            if (! is_array($entry) || ! isset($entry['path'], $entry['status'])) {
                throw AgentRuntimeException::make(
                    AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                    'Runtime diff entry did not have the expected shape.'
                );
            }
            $diffs[] = [
                'path' => (string) $entry['path'],
                'status' => (string) $entry['status'],
                'additions' => (int) ($entry['additions'] ?? 0),
                'deletions' => (int) ($entry['deletions'] ?? 0),
                'patch' => (string) ($entry['patch'] ?? ''),
            ];
        }

        return $diffs;
    }

    /** @return array<string, array{type: string, attempt?: int}> session id → status map */
    public function sessionStatus(string $directory): array
    {
        $json = $this->getJson('/session/status', ['directory' => $directory]);

        if (! is_array($json)) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Runtime session status response did not have the expected shape.'
            );
        }

        $out = [];
        foreach ($json as $sessionId => $status) {
            if (is_string($sessionId) && is_array($status)) {
                $out[$sessionId] = ['type' => (string) ($status['type'] ?? 'unknown')];
            }
        }

        return $out;
    }

    public function abort(string $sessionId): void
    {
        $this->post("/session/{$sessionId}/abort");
    }

    public function replyPermission(string $requestId, string $reply): void
    {
        $this->post("/permission/{$requestId}/reply", ['reply' => $reply]);
    }

    // ── Event stream ────────────────────────────────────────────────────

    /**
     * Read the official SSE stream (`GET /event?directory=…`) line by line,
     * yielding decoded `data:` frames. Ends when the idle timeout passes
     * without a frame (normal completion: sessions go quiet when idle).
     *
     * @return \Generator<int, array{type: string, properties: mixed}>
     */
    public function streamEvents(?string $directory, int $idleTimeoutSeconds): \Generator
    {
        $query = $directory !== null ? ['directory' => $directory] : [];
        $connectionCap = max(1, min($idleTimeoutSeconds, 30));

        try {
            $response = $this->http
                ->withOptions([
                    'stream' => true,
                    // Hard per-connection cap: if the runtime stalls, the read
                    // throws (curl 28) and the connection is closed — the task
                    // service reconnects until its own budgets run out.
                    'timeout' => $connectionCap,
                    'read_timeout' => $connectionCap,
                ])
                ->get('/event', $query);
        } catch (ConnectionException $e) {
            throw AgentRuntimeException::make(AgentRuntimeException::RUNTIME_UNAVAILABLE, 'Runtime event stream could not be opened.', 0, $e);
        }

        if (! $response->successful()) {
            $this->throwForStatus($response, 'event stream');
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $deadline = microtime(true) + $idleTimeoutSeconds;

        while (true) {
            try {
                $chunk = $body->read(8192);
            } catch (\Throwable $e) {
                break; // read timeout / stream closed — treated as idle end
            }

            if ($chunk === '') {
                if (microtime(true) >= $deadline) {
                    break;
                }
                usleep(50_000);

                continue;
            }

            $deadline = microtime(true) + $idleTimeoutSeconds; // activity resets the idle window
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                $frame = self::parseSseLine($line);
                if ($frame !== null) {
                    yield $frame;
                }
            }
        }

        $body->close();
    }

    /** @return array{type: string, properties: mixed}|null */
    public static function parseSseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || ! Str::startsWith($line, 'data:')) {
            return null;
        }

        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! isset($decoded['type']) || ! is_string($decoded['type'])) {
            return null; // keepalive/comment garbage is skipped, not fatal
        }

        return ['type' => $decoded['type'], 'properties' => $decoded['properties'] ?? []];
    }

    // ── Primitives ──────────────────────────────────────────────────────

    protected function getJson(string $path, array $query = []): mixed
    {
        return $this->decode($this->send(fn () => $this->http->get($path, $query)), $path);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    protected function postJson(string $path, array $body, array $query = []): mixed
    {
        return $this->decode($this->send(fn () => $this->http->post($path.'?'.http_build_query($query), $body)), $path);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     */
    protected function post(string $path, ?array $body = null, array $query = [], ?int $expectStatus = null): void
    {
        $response = $this->send(fn () => $this->http->post($path.'?'.http_build_query($query), $body ?? []));

        if ($expectStatus !== null && $response->status() !== $expectStatus) {
            $this->throwForStatus($response, $path);
        }

        if ($expectStatus === null && $response->status() >= 400) {
            $this->throwForStatus($response, $path);
        }
    }

    protected function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            // Distinguish "took too long" from "could not connect" without
            // string-matching exception text into the user message.
            throw AgentRuntimeException::make(
                str_contains(strtolower($e->getMessage()), 'timed out')
                    ? AgentRuntimeException::TIMEOUT
                    : AgentRuntimeException::RUNTIME_UNAVAILABLE,
                'Agent runtime did not respond.',
                0,
                $e
            );
        }
    }

    protected function decode(Response $response, string $path): mixed
    {
        if ($response->status() >= 400) {
            $this->throwForStatus($response, $path);
        }

        $json = json_decode($response->body(), true);

        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::INVALID_RUNTIME_RESPONSE,
                'Agent runtime returned a malformed response.'
            );
        }

        return $json;
    }

    protected function throwForStatus(Response $response, string $what): never
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw AgentRuntimeException::make(
                AgentRuntimeException::RUNTIME_AUTH_FAILED,
                'Agent runtime rejected the configured credentials.',
                $response->status()
            );
        }

        throw AgentRuntimeException::make(
            AgentRuntimeException::RUNTIME_UNAVAILABLE,
            "Agent runtime rejected the request ({$what}).",
            $response->status()
        );
    }

    /** Split canonical `provider/model` (first slash); null-safe for unset models. */
    public static function splitModel(?string $canonical): array
    {
        if ($canonical === null || $canonical === '' || ! str_contains($canonical, '/')) {
            return [null, null];
        }

        [$provider, $model] = explode('/', $canonical, 2);

        return [$provider, $model];
    }

    /** Normalize and compare two directory strings across OS separators. */
    public static function sameDirectory(string $a, string $b): bool
    {
        $norm = fn (string $p) => strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($p, '\/')));
        $wa = realpath($a) ?: $a;
        $wb = realpath($b) ?: $b;

        return $norm($wa) === $norm($wb);
    }
}
