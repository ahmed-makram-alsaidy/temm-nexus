// Minimal Flutter/Dart integration example (Phase 22, not a product).
//
// Production wiring: persist the token with flutter_secure_storage via
// SecureTokenStoreAdapter — never plaintext shared_preferences.
//
//   final storage = FlutterSecureStorage();
//   final backend = BackendClient(
//     baseUrl: 'https://api.example.com',
//     apiKey: 'cp_CLIENT_SAFE_KEY',
//     tokenStore: SecureTokenStoreAdapter(
//       read: ({required key}) => storage.read(key: key),
//       write: ({required key, required value}) =>
//           value == null ? storage.delete(key: key) : storage.write(key: key, value: value),
//       delete: ({required key}) => storage.delete(key: key),
//     ),
//     projectSlug: 'acme',
//     functionsBaseUrl: 'https://console.example.com',
//     realtimeWsUrl: 'ws://reverb.example.com:8080/app/KEY?protocol=7',
//   );
//   await backend.auth.login(email: email, password: password);
//   final me = await backend.auth.me();
//   await backend.functions.invoke('hello-platform', body: {'name': me.name});
//   final sub = await backend.realtime.subscribe(
//     channel: 'orders',
//     onEvent: (e) => debugPrint('${e.event}: ${e.data}'),
//   );
//   // ...
//   await sub.unsubscribe();
//   await backend.auth.logout();
void main() {}
