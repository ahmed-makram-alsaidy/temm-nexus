# PHP / server-to-server SDK

Package: `packages/backend-sdk-php` (`platform/backend-sdk` 0.1.0, private).
No runtime deps (`ext-curl` + `ext-json`). For service integration and internal
automation — NOT for browsers or user login flows (no login/logout here by design).

```bash
composer test   # dependency-free runner: php tests/run-tests.php
```

```php
use Platform\BackendSdk\BackendClient;

$backend = new BackendClient(
    'https://api.example.com',
    getenv('API_SECRET_KEY'), // SERVER-ONLY key. Never in git, never in responses.
    'acme',                  // project slug for /f/…
    'https://console.example.com',
);

$backend->health();                                            // ['ok' => true, …]
$res = $backend->functions()->invoke('hello-platform', ['name' => 'Ada']);
// ['data'=>…, 'status'=>200, 'requestId'=>…, 'durationMs'=>…, 'version'=>…]
$url = $backend->storage()->signedUrl('invoices', '2026/09/inv-1.pdf');
$backend->storage()->upload('avatars', '/tmp/p.jpg', ['visibility' => 'private']);
BackendClient::qs(2, 25, 'ada', ['name','-created_at'], ['status' => 'active']);
Realtime::validateChannel('orders'); // same regex the server enforces
```

Errors: `Platform\BackendSdk\ApiError` with `getMessage()`, `getCode()`
(HTTP status), `$e->errorCode` (stable code — named so because
`Exception::$code` is taken), `$e->errors` (422), `$e->requestId`,
`$e->retryAfterMs` (429). `ApiError::redactHeaders()` before logging.
Inject any `$transport` callable for tests (see `tests/run-tests.php`).

Laravel-package pattern: bind one `BackendClient` singleton per project in a
service provider, key from `config/services.php` (env-sourced), never `.env`
committed. Per-request user tokens are out of scope here — use the JS/Dart SDKs.
