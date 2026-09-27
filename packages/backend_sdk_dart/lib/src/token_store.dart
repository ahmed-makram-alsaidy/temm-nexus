/// Token persistence abstraction. The SDK core never picks a store for you.
library;

/// Minimal contract the SDK needs. Implementations decide durability.
abstract class TokenStore {
  Future<String?> get();
  Future<void> set(String? token);
  Future<void> clear();
}

/// Default. Process memory only. Safe for tests and short-lived clients.
class MemoryTokenStore implements TokenStore {
  String? _token;

  @override
  Future<String?> get() async => _token;

  @override
  Future<void> set(String? token) async {
    _token = token;
  }

  @override
  Future<void> clear() async {
    _token = null;
  }
}

/// Adapter for `flutter_secure_storage` (recommended for production).
///
/// Wire it: `SecureTokenStoreAdapter(read: storage.read, write: ..., delete: ...)`.
///
/// SECURITY: never persist user tokens or API keys in plaintext
/// `shared_preferences` in production — prefs XML is world-readable on rooted
/// devices and unencrypted at rest. Use `flutter_secure_storage` (Keychain /
/// EncryptedSharedPreferences) via this adapter.
class SecureTokenStoreAdapter implements TokenStore {
  final Future<String?> Function({required String key}) _read;
  final Future<void> Function({required String key, required String? value}) _write;
  final Future<void> Function({required String key}) _delete;
  final String storageKey;

  const SecureTokenStoreAdapter({
    required Future<String?> Function({required String key}) read,
    required Future<void> Function({required String key, required String? value}) write,
    required Future<void> Function({required String key}) delete,
    this.storageKey = 'backend.auth.token',
  })  : _read = read,
        _write = write,
        _delete = delete;

  @override
  Future<String?> get() => _read(key: storageKey);

  @override
  Future<void> set(String? token) => _write(key: storageKey, value: token);

  @override
  Future<void> clear() => _delete(key: storageKey);
}
