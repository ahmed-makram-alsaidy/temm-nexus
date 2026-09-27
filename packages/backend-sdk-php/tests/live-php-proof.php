<?php

declare(strict_types=1);

/**
 * Phase 22.1 PHP SDK LIVE proof — server-to-server against the real stack.
 * No mocks. Uses the disposable gate key from GATE_KEY env (never hardcoded).
 *
 * Run (from repo root):
 *   docker run --rm --network backend-infra-application \
 *     -v "<repo>/packages/backend-sdk-php:/sdk" -w /sdk \
 *     -e GATE_KEY=... -e LIVE_API_URL=http://sdklive-a-web:8000 \
 *     -e LIVE_FUNCTIONS_URL=http://caddy -e LIVE_HOST=console.test \
 *     backend-infra/owner-console-php:8.4 php /sdk/tests/live-php-proof.php
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Platform\\BackendSdk\\';
    if (str_starts_with($class, $prefix)) {
        require '/sdk/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use Platform\BackendSdk\ApiError;
use Platform\BackendSdk\BackendClient;

$passed = 0;
function check(string $name, callable $fn): void
{
    global $passed;
    try {
        $fn();
        $passed++;
        echo "ok - {$name}\n";
    } catch (\Throwable $e) {
        echo 'FAIL - '.$name.': '.get_class($e).': '.$e->getMessage()."\n";
        exit(1);
    }
}
function assertSame(mixed $expected, mixed $actual, string $hint = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException("assertSame failed ($hint): expected ".var_export($expected, true).', got '.var_export($actual, true));
    }
}

$key = getenv('GATE_KEY') ?: '';
if ($key === '') {
    echo "GATE_KEY env required\n";
    exit(1);
}
$api = getenv('LIVE_API_URL') ?: 'http://sdklive-a-web:8000';
$functions = getenv('LIVE_FUNCTIONS_URL') ?: 'http://caddy';
$host = getenv('LIVE_HOST') ?: 'console.test';

// Caddy routes by Host; from inside docker we address it directly.
$sdk = new BackendClient($api, $key, 'sdk-live-a', $functions, 'bearer', 15000, null);

check('health via live project API (no auth)', function () use ($sdk): void {
    $health = $sdk->health();
    assertSame(true, $health['ok'] ?? null, 'ok');
    assertSame(true, $health['database']['ok'] ?? null, 'db');
    assertSame(true, $health['redis']['ok'] ?? null, 'redis');
});

check('functions.invoke live with request id', function () use ($sdk, $host): void {
    $http = new Platform\BackendSdk\HttpClient(
        $sdk->functionsHttp->baseUrl,
        getenv('GATE_KEY') ?: '',
        'bearer',
        15000,
        null,
        ['Host' => $host],
    );
    $fn = (new Platform\BackendSdk\Functions($http, 'sdk-live-a'))->invoke('gate-hello', ['name' => 'Ada']);
    assertSame(200, $fn['status'], 'status');
    assertSame('hello, Ada', $fn['data']['greeting'] ?? null, 'greeting');
    assertSame(true, strlen($fn['requestId']) > 0, 'request id');
});

check('bad key maps to 401 UNAUTHENTICATED (live)', function () use ($sdk, $host): void {
    $http = new Platform\BackendSdk\HttpClient(
        $sdk->functionsHttp->baseUrl, 'cp_gate221a.wrongsecret00000000000000000000000000',
        'bearer', 15000, null, ['Host' => $host],
    );
    try {
        (new Platform\BackendSdk\Functions($http, 'sdk-live-a'))->invoke('gate-hello', ['name' => 'Ada']);
        throw new \RuntimeException('expected ApiError');
    } catch (ApiError $e) {
        assertSame('UNAUTHENTICATED', $e->errorCode, 'code');
        assertSame(401, $e->status, 'status');
        assertSame(true, strlen($e->requestId ?? '') > 0, 'request id echoed');
    }
});

check('qs() builds the live query string', function (): void {
    $q = BackendClient::qs(2, 25, 'ada', ['name', '-created_at'], ['status' => 'active']);
    assertSame('name,-created_at', $q['sort'], 'sort');
    assertSame('active', $q['filter[status]'], 'filter');
});

echo "\nLIVE PHP PROOF PASS ({$passed} checks, real stack)\n";
