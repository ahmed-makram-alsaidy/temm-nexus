# Flutter / Dart SDK

Package: `packages/backend_sdk_dart` 0.1.0 (private; `http` + `web_socket_channel`, pinned).
Contract: `docs/CLIENT_API_CONTRACT.md` (Platform API v1).

```yaml
dependencies:
  backend_sdk:
    path: ../../packages/backend_sdk_dart   # Phase 22: path/git until published
```

```dart
final backend = BackendClient(
  baseUrl: 'https://api.example.com',
  apiKey: 'cp_…', // CLIENT-SAFE key only. Never a server secret in the app.
  projectSlug: 'acme',
  functionsBaseUrl: 'https://console.example.com',
  realtimeWsUrl: 'ws://reverb.example.com:8080/app/KEY?protocol=7',
);

await backend.auth.login(email: email, password: password);
final me = await backend.auth.me();
await backend.auth.forgotPassword(email);
await backend.storage.upload(bucket: 'avatars', bytes: bytes, fileName: 'p.jpg');
final url = await backend.storage.signedUrl('invoices', '2026/09/inv-1.pdf');
final res = await backend.functions.invoke('hello-platform', body: {'name': me.name});
final sub = await backend.realtime.subscribe(
  channel: 'orders',
  onEvent: (e) => debugPrint('${e.event}: ${e.data}'),
);
await sub.unsubscribe();
await backend.auth.logout();
```

## Auth persistence

The SDK never picks storage. Default `MemoryTokenStore` (tests/short sessions).
Production MUST use `SecureTokenStoreAdapter` + `flutter_secure_storage`
(Keychain / EncryptedSharedPreferences) — see `example/main.dart`. Plaintext
`shared_preferences` is NOT acceptable for tokens (readable at rest, see
`SECURITY.md`).

## Networking

JSON + multipart uploads, per-call timeouts (`timeout:`), error mapping to
`BackendException` (`code/status/errors/requestId/retryAfter`), auth injection
(user token wins; API key otherwise), `X-Request-ID` per call. Retries: only
safe idempotent GETs after the server's `Retry-After` — never blind retries of
POST/PUT/PATCH/DELETE.

## Runtime status

`dart test` covers auth/http/functions/realtime-validation. Flutter-widget /
device proof requires the Flutter toolchain, absent in this workspace
(environment-blocked, not faked — see the Phase 22 report).
