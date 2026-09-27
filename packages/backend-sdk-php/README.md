# platform/backend-sdk (PHP)

Official **server-to-server** PHP client for the Laravel Backend Platform (API v1).
No runtime dependencies (`ext-curl` + `ext-json` only). See
`docs/CLIENT_API_CONTRACT.md` for the wire contract and `docs/client-sdk/PHP.md`.

```php
$backend = new Platform\BackendSdk\BackendClient(
    'https://api.example.com',
    getenv('API_SECRET_KEY'), // SERVER-ONLY key. Never in git, never in the browser.
    'acme',                  // project slug (functions)
    'https://console.example.com',
);

$backend->health(); // ['ok' => true, ...]
$res = $backend->functions()->invoke('hello-platform', ['name' => 'Ada']);
// ['data' => [...], 'status' => 200, 'requestId' => '...', ...]
$url = $backend->storage()->signedUrl('invoices', '2026/09/inv-1.pdf');
```

User-token flows (login/logout) are intentionally absent — this client
authenticates as a service with an API key. Browser/mobile apps must use the
JS/Dart SDKs with CLIENT-SAFE keys.

## Local use (this phase — not published to Packagist)

```json
"repositories": [{ "type": "path", "url": "../../packages/backend-sdk-php" }],
"require": { "platform/backend-sdk": "@dev" }
```

## Tests (no phpunit required)

```bash
composer test
# watchdog-free alternative on any PHP 8.1+ CLI:
php tests/run-tests.php
# via the pinned platform PHP without installing anything host-wide:
docker run --rm -v ${PWD}:/sdk -w /sdk backend-infra/owner-console-php:8.4 php tests/run-tests.php
```
