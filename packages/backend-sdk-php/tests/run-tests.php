<?php

declare(strict_types=1);

/**
 * Dependency-free test runner for platform/backend-sdk (Phase 22).
 * Runs on plain PHP 8.1+ CLI — no phpunit required:
 *
 *   php tests/run-tests.php
 *   # or via docker: docker run --rm -v ${PWD}:/sdk -w /sdk backend-infra/owner-console-php:8.4 php tests/run-tests.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\BackendSdk\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Platform\BackendSdk\ApiError;
use Platform\BackendSdk\BackendClient;
use Platform\BackendSdk\Realtime;

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "ok - {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL - {$name}: ".get_class($e).': '.$e->getMessage()."\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $hint = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            'assertSame failed'.($hint !== '' ? " ({$hint})" : '').': expected '.var_export($expected, true).', got '.var_export($actual, true)
        );
    }
}

function json(int $status, mixed $body, array $headers = []): array
{
    return [$status, array_change_key_case($headers, CASE_LOWER), is_string($body) ? $body : json_encode($body)];
}

/** Route-aware mock transport. Returns [ArrayObject $calls, callable $transport]. */
function mockTransport(array $routes): array
{
    $calls = new \ArrayObject([]);
    $transport = function (string $method, string $url, array $headers, mixed $body) use ($calls, $routes) {
        $map = [];
        foreach ($headers as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $map[strtolower(trim($k))] = trim($v);
            }
        }
        $calls->append(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'hmap' => $map]);
        foreach ($routes as [$match, $handler]) {
            if (str_contains($url, $match)) {
                return $handler($url, $map, $body, $calls);
            }
        }

        return json(404, ['message' => 'Not Found']);
    };

    return [$calls, $transport];
}

// --- health + request id ----------------------------------------------------

check('health() parses ok + honors echoed request id', function (): void {
    [$calls, $transport] = mockTransport([
        ['/api/health', fn () => json(200, ['ok' => true], ['x-request-id' => 'srv_1'])],
    ]);
    $sdk = new BackendClient('https://api.example.com/', null, null, null, 'bearer', 15000, $transport);
    $health = $sdk->health();
    assertSame(true, $health['ok']);
    assertSame(true, isset($calls[0]['hmap']['x-request-id']) && $calls[0]['hmap']['x-request-id'] !== '');
});

// --- api key placement ------------------------------------------------------

check('api key defaults to Bearer, header placement opt-in', function (): void {
    [$calls1, $t1] = mockTransport([['/api/health', fn () => json(200, ['ok' => true])]]);
    (new BackendClient('https://api.example.com', 'cp_abc.secret', null, null, 'bearer', 15000, $t1))->health();
    assertSame('Bearer cp_abc.secret', $calls1[0]['hmap']['authorization'] ?? null);

    [$calls2, $t2] = mockTransport([['/api/health', fn () => json(200, ['ok' => true])]]);
    (new BackendClient('https://api.example.com', 'cp_abc.secret', null, null, 'header', 15000, $t2))->health();
    assertSame('cp_abc.secret', $calls2[0]['hmap']['x-api-key'] ?? null);
});

check('no credential is sent when none is configured', function (): void {
    [$calls, $transport] = mockTransport([['/api/health', fn () => json(200, ['ok' => true])]]);
    (new BackendClient('https://api.example.com', null, null, null, 'bearer', 15000, $transport))->health();
    assertSame(false, isset($calls[0]['hmap']['authorization']));
    assertSame(false, isset($calls[0]['hmap']['x-api-key']));
});

// --- error mapping ----------------------------------------------------------

check('maps 401/422/429/503 to stable codes', function (): void {
    [$c1, $t1] = mockTransport([['/api/health', fn () => json(401, ['message' => 'Invalid API key.'], ['x-request-id' => 'r1'])]]);
    try {
        (new BackendClient('https://api.example.com', 'cp_bad.x', null, null, 'bearer', 15000, $t1))->health();
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame('UNAUTHENTICATED', $e->errorCode);
        assertSame('Invalid API key.', $e->getMessage());
        assertSame('r1', $e->requestId);
    }

    [$c2, $t2] = mockTransport([['/x', fn () => json(422, ['message' => 'Validation failed', 'errors' => ['email' => ['required']]])]]);
    try {
        (new BackendClient('https://x.test', null, null, null, 'bearer', 15000, $t2))->http->get('/x');
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame('VALIDATION_ERROR', $e->errorCode);
        assertSame(['email' => ['required']], $e->errors);
    }

    [$c3, $t3] = mockTransport([['/x', fn () => json(429, ['message' => 'Too Many Requests'], ['retry-after' => '2'])]]);
    try {
        (new BackendClient('https://x.test', null, null, null, 'bearer', 15000, $t3))->http->get('/x');
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame('RATE_LIMITED', $e->errorCode);
        assertSame(2000, $e->retryAfterMs);
    }

    [$c4, $t4] = mockTransport([['/x', fn () => json(503, ['error' => 'Function is disabled.'])]]);
    try {
        (new BackendClient('https://x.test', null, null, null, 'bearer', 15000, $t4))->http->get('/x');
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame('UNAVAILABLE', $e->errorCode);
        assertSame('Function is disabled.', $e->getMessage());
    }
});

check('ApiError never carries credential material', function (): void {
    [$calls, $transport] = mockTransport([['/x', fn () => json(401, ['message' => 'Invalid API key.'])]]);
    try {
        (new BackendClient('https://x.test', 'cp_abc.SUPERSECRET', null, null, 'bearer', 15000, $transport))->http->get('/x');
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame(false, str_contains((string) $e, 'SUPERSECRET'));
        $redacted = ApiError::redactHeaders(['Authorization' => 'Bearer cp_abc.SUPERSECRET', 'Content-Type' => 'application/json']);
        assertSame('[REDACTED]', $redacted['Authorization']);
        assertSame('application/json', $redacted['Content-Type']);
    }
});

// --- functions ---------------------------------------------------------------

check('functions.invoke hits /f/{project}/{slug} on the functions host', function (): void {
    [$calls, $transport] = mockTransport([
        ['/f/acme/hello-platform', fn () => json(200, ['greeting' => 'hello'], ['x-request-id' => 'freq_1'])],
    ]);
    $sdk = new BackendClient('https://api.example.com', 'cp_k.s', 'acme', 'https://console.test', 'bearer', 15000, $transport);
    $res = $sdk->functions()->invoke('hello-platform', ['name' => 'Ada']);
    assertSame('https://console.test/f/acme/hello-platform', $calls[0]['url']);
    assertSame(200, $res['status']);
    assertSame('freq_1', $res['requestId']);
    assertSame('hello', $res['data']['greeting']);
});

check('functions.invoke without projectSlug throws clearly', function (): void {
    [$calls, $transport] = mockTransport([['/f', fn () => json(200, [])]]);
    try {
        (new BackendClient('https://api.example.com', null, null, null, 'bearer', 15000, $transport))->functions()->invoke('x');
        throw new \RuntimeException('expected LogicException');
    } catch (\LogicException $e) {
        assertSame(true, str_contains($e->getMessage(), 'projectSlug'));
    }
});

check('setApiKey rotates both transports', function (): void {
    [$calls, $transport] = mockTransport([['/api/health', fn () => json(200, ['ok' => true])]]);
    $sdk = new BackendClient('https://api.example.com', null, 'acme', 'https://c.test', 'bearer', 15000, $transport);
    $sdk->setApiKey('cp_one.s1');
    assertSame('cp_one.s1', $sdk->http->apiKey);
    assertSame('cp_one.s1', $sdk->functionsHttp->apiKey);
});

// --- storage -----------------------------------------------------------------

check('storage.signedUrl returns the server URL', function (): void {
    [$calls, $transport] = mockTransport([
        ['/signed-url', fn () => json(200, ['url' => 'https://console.test/sdl/p/b/k?expires=1&signature=abc'])],
    ]);
    $sdk = new BackendClient('https://api.example.com', 'cp_k.s', null, null, 'bearer', 15000, $transport);
    $url = $sdk->storage()->signedUrl('b', 'k');
    assertSame(true, str_contains($url, '/sdl/'));
});

check('storage.upload sends multipart to the bucket path', function (): void {
    [$calls, $transport] = mockTransport([
        ['/api/v1/storage/avatars/upload', fn () => json(201, ['bucket' => 'avatars', 'key' => 'u/1/p.jpg'])],
    ]);
    $tmp = tempnam(sys_get_temp_dir(), 'sdk');
    file_put_contents($tmp, 'bytes');
    try {
        $sdk = new BackendClient('https://api.example.com', 'cp_k.s', null, null, 'bearer', 15000, $transport);
        $obj = $sdk->storage()->upload('avatars', $tmp, ['visibility' => 'private']);
        assertSame(true, str_contains($calls[0]['url'], '/api/v1/storage/avatars/upload'));
        assertSame(true, ($calls[0]['body']['__multipart__'] ?? false));
        assertSame('private', $calls[0]['body']['fields']['visibility']);
        assertSame('u/1/p.jpg', $obj['key']);
    } finally {
        @unlink($tmp);
    }
});

// --- realtime helpers + qs ----------------------------------------------------

check('realtime channel rules mirror the server', function (): void {
    assertSame('orders', Realtime::validateChannel('orders'));
    assertSame(true, Realtime::isPrivate('private-orders'));
    assertSame(false, Realtime::isPrivate('orders'));
    try {
        Realtime::validateChannel('bad channel!');
        throw new \RuntimeException('expected InvalidArgumentException');
    } catch (\InvalidArgumentException) {
    }
    assertSame('ws://h:8080/app/k?protocol=7', Realtime::wsUrl('h', 8080, 'k'));
});

check('qs() builds the contract query', function (): void {
    $q = BackendClient::qs(2, 25, 'ada', ['name', '-created_at'], ['status' => 'active']);
    assertSame(['page' => 2, 'per_page' => 25, 'search' => 'ada', 'sort' => 'name,-created_at', 'filter[status]' => 'active'], $q);
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
