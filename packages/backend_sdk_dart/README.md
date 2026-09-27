# backend_sdk_dart

Official Dart/Flutter client for the Laravel Backend Platform (API v1).
Pinned dependencies (`http`, `web_socket_channel`). See
`docs/CLIENT_API_CONTRACT.md` for the wire contract and
`docs/client-sdk/FLUTTER.md` for the guide.

```dart
final backend = BackendClient(
  baseUrl: 'https://api.example.com',
  apiKey: 'cp_…', // CLIENT-SAFE key only. Never a server secret in the app.
);
await backend.auth.login(email: email, password: password);
final me = await backend.auth.me();
await backend.auth.logout();
```

## Token storage

The SDK never picks persistence for you. `MemoryTokenStore` is the default.
Production apps MUST use `SecureTokenStoreAdapter` backed by
`flutter_secure_storage` (see `example/main.dart`). Plaintext
`shared_preferences` is NOT acceptable for tokens in production.

## Local use (this phase — not published to pub.dev)

```yaml
dependencies:
  backend_sdk:
    path: ../../packages/backend_sdk_dart
```

```bash
cd packages/backend_sdk_dart
dart pub get
dart test
```

## Flutter runtime proof

Requires the Flutter toolchain, which is absent in this workspace
(see `PHASE22_CLIENT_INTEGRATION_KIT_REPORT.md` → environment-blocked).
`dart test` covers auth/http/functions/realtime-validation logic; the
`example/main.dart` wiring is reviewed but not executed here.
