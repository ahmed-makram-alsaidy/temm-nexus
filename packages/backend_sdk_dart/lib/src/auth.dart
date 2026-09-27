/// User-token auth: register / login / logout / me / password flows.
library;

import 'http_client.dart';
import 'types.dart';

class AuthModule {
  final BackendHttp _http;
  const AuthModule(this._http);

  /// Laravel wraps a top-level JsonResource in `{data: …}` — accept both.
  static Map<String, dynamic> _userOf(Object? payload) {
    if (payload is Map<String, dynamic>) {
      final inner = payload['data'];
      if (inner is Map<String, dynamic> && inner.containsKey('email')) {
        return inner;
      }
      return payload;
    }
    throw ArgumentError('Unexpected user payload: $payload');
  }

  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    String? deviceName,
  }) async {
    final res = await _http.request('POST', '/api/v1/auth/register', body: {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': password,
      if (deviceName != null) 'device_name': deviceName,
    });
    final data = res.data as Map<String, dynamic>;
    final session = AuthSession(
      user: BackendUser.fromJson(_userOf(data['user'])),
      token: data['token'] as String,
    );
    await _http.tokenStore.set(session.token);
    return session;
  }

  Future<AuthSession> login({
    required String email,
    required String password,
    String? deviceName,
  }) async {
    final res = await _http.request('POST', '/api/v1/auth/login', body: {
      'email': email,
      'password': password,
      if (deviceName != null) 'device_name': deviceName,
    });
    final data = res.data as Map<String, dynamic>;
    final session = AuthSession(
      user: BackendUser.fromJson(_userOf(data['user'])),
      token: data['token'] as String,
    );
    await _http.tokenStore.set(session.token);
    return session;
  }

  /// Revokes the current token server-side, then clears local storage.
  Future<void> logout() async {
    try {
      await _http.request('POST', '/api/v1/auth/logout', body: {});
    } finally {
      await _http.tokenStore.clear();
    }
  }

  Future<BackendUser> me() async {
    final res = await _http.request('GET', '/api/v1/user');
    return BackendUser.fromJson(_userOf(res.data));
  }

  /// Existence-safe: always completes, never reveals whether the email exists.
  Future<void> forgotPassword(String email) async {
    await _http.request('POST', '/api/v1/auth/forgot-password', body: {'email': email});
  }

  Future<void> resetPassword({
    required String token,
    required String email,
    required String password,
  }) async {
    await _http.request('POST', '/api/v1/auth/reset-password', body: {
      'token': token,
      'email': email,
      'password': password,
      'password_confirmation': password,
    });
  }

  Future<AuthSession> refresh() async {
    final res = await _http.request('POST', '/api/v1/auth/refresh', body: {});
    final data = res.data as Map<String, dynamic>;
    final session = AuthSession(
      user: BackendUser.fromJson(_userOf(data['user'])),
      token: data['token'] as String,
    );
    await _http.tokenStore.set(session.token);
    return session;
  }

  Future<String?> getToken() => _http.tokenStore.get();

  Future<void> setToken(String? token) =>
      token == null ? _http.tokenStore.clear() : _http.tokenStore.set(token);
}
