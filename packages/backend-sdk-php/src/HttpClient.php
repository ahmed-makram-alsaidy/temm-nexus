<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/**
 * cURL-based HTTP layer: API-key injection, request IDs, timeouts, error mapping.
 *
 * A custom transport may be injected for tests:
 * `fn(string $method, string $url, array $headers, mixed $body): array{status:int,headers:array,body:string}`
 * where `$body` is the JSON string, or (multipart only) `['__multipart__' => true, 'fields' => array]`.
 */
class HttpClient
{
    public readonly string $baseUrl;
    public ?string $apiKey;
    public readonly string $apiKeyPlacement; // 'bearer' | 'header'
    public readonly int $timeoutMs;
    /** @var callable|null */
    private $transport;
    private array $defaultHeaders;

    public function __construct(
        string $baseUrl,
        ?string $apiKey = null,
        string $apiKeyPlacement = 'bearer',
        int $timeoutMs = 15000,
        ?callable $transport = null,
        array $defaultHeaders = [],
    ) {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('baseUrl is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiKeyPlacement = $apiKeyPlacement;
        $this->timeoutMs = $timeoutMs;
        $this->transport = $transport;
        $this->defaultHeaders = $defaultHeaders;
    }

    public static function newRequestId(): string
    {
        return 'req_'.bin2hex(random_bytes(16));
    }

    /** Build headers. Explicit per-call Authorization is never overwritten. */
    public function buildHeaders(array $explicit = [], ?string $apiKeyOverride = null, bool $keyProvided = false): array
    {
        $headers = array_merge(['Accept' => 'application/json'], $this->defaultHeaders);
        $key = $keyProvided ? $apiKeyOverride : $this->apiKey;
        if ($key !== null && $key !== '' && ! isset($headers['Authorization'])) {
            if ($this->apiKeyPlacement === 'header') {
                $headers['X-API-Key'] = $key;
            } else {
                $headers['Authorization'] = "Bearer {$key}";
            }
        }
        foreach ($explicit as $k => $v) {
            $headers[$k] = $v;
        }

        return $headers;
    }

    /**
     * @return array{data:mixed,status:int,headers:array,requestId:string}
     */
    public function request(string $method, string $path, array $opts = []): array
    {
        $requestId = $opts['requestId'] ?? self::newRequestId();
        $query = $opts['query'] ?? [];
        $filtered = array_filter($query, fn ($v) => $v !== null);
        $url = $this->baseUrl.(str_starts_with($path, '/') ? '' : '/').$path;
        if ($filtered !== []) {
            $url .= '?'.http_build_query($filtered);
        }
        $headers = $this->buildHeaders(
            array_merge(['X-Request-ID' => $requestId], $opts['headers'] ?? []),
            $opts['apiKey'] ?? null,
            array_key_exists('apiKey', $opts),
        );
        $body = null;
        if (array_key_exists('body', $opts) && $opts['body'] !== null) {
            $headers['Content-Type'] ??= 'application/json';
            $body = is_string($opts['body']) ? $opts['body'] : json_encode($opts['body']);
        }

        [$status, $resHeaders, $raw] = $this->send($method, $url, $headers, $body, $opts['timeoutMs'] ?? $this->timeoutMs);
        $responseId = $this->header($resHeaders, 'x-request-id') ?? $requestId;
        $parsed = $raw === '' ? null : (json_decode($raw, true) ?? $raw);
        if ($status < 200 || $status >= 300) {
            throw ApiError::fromHttp($status, $parsed, $responseId, $this->retryAfterMs($resHeaders));
        }

        return ['data' => $parsed, 'status' => $status, 'headers' => $resHeaders, 'requestId' => $responseId];
    }

    public function get(string $path, array $opts = []): array
    {
        return $this->request('GET', $path, $opts);
    }

    public function post(string $path, mixed $body = null, array $opts = []): array
    {
        return $this->request('POST', $path, $opts + ['body' => $body]);
    }

    public function delete(string $path, array $opts = []): array
    {
        return $this->request('DELETE', $path, $opts);
    }

    /** Multipart upload (field `file`). */
    public function upload(string $path, string $filePath, array $fields = [], array $opts = []): array
    {
        $requestId = $opts['requestId'] ?? self::newRequestId();
        $url = $this->baseUrl.(str_starts_with($path, '/') ? '' : '/').$path;
        $headers = $this->buildHeaders(
            array_merge(['X-Request-ID' => $requestId], $opts['headers'] ?? []),
            $opts['apiKey'] ?? null,
            array_key_exists('apiKey', $opts),
        );
        unset($headers['Content-Type']); // cURL sets the multipart boundary
        $post = $fields;
        $post['file'] = new \CURLFile($filePath);

        [$status, $resHeaders, $raw] = $this->send('POST', $url, $headers, $post, $opts['timeoutMs'] ?? 60000, true);
        $responseId = $this->header($resHeaders, 'x-request-id') ?? $requestId;
        $parsed = $raw === '' ? null : (json_decode($raw, true) ?? $raw);
        if ($status < 200 || $status >= 300) {
            throw ApiError::fromHttp($status, $parsed, $responseId, $this->retryAfterMs($resHeaders));
        }

        return ['data' => $parsed, 'status' => $status, 'headers' => $resHeaders, 'requestId' => $responseId];
    }

    /** @return array{int,array,string} status, headers, body */
    private function send(string $method, string $url, array $headers, mixed $body, int $timeoutMs, bool $multipart = false): array
    {
        if ($this->transport !== null) {
            $headerLines = [];
            foreach ($headers as $k => $v) {
                $headerLines[] = "{$k}: {$v}";
            }
            $payload = $multipart
                ? ['__multipart__' => true, 'fields' => $body]
                : (is_string($body) ? $body : null);

            return ($this->transport)($method, $url, $headerLines, $payload);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMs),
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR';
            throw new ApiError(
                $code === 'TIMEOUT' ? "Request timed out after {$timeoutMs}ms" : ($err !== '' ? $err : 'Network error'),
                $code, 0, null, null, null,
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $resHeaders = [];
        foreach (explode("\r\n", substr((string) $raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $resHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return [$status, $resHeaders, substr((string) $raw, $headerSize)];
    }

    private function header(array $headers, string $name): ?string
    {
        return $headers[strtolower($name)] ?? null;
    }

    private function retryAfterMs(array $headers): ?int
    {
        $v = $this->header($headers, 'retry-after');
        if ($v === null || ! is_numeric(trim($v)) || (int) $v < 0) {
            return null;
        }

        return ((int) $v) * 1000;
    }
}
