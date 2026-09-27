/// Server functions (`/f/{project}/{function}` on the functions host).
library;

import 'http_client.dart';
import 'types.dart';

class FunctionsModule {
  final BackendHttp _http;
  final String? projectSlug;

  const FunctionsModule(this._http, this.projectSlug);

  String _path(String slug) {
    final p = projectSlug;
    if (p == null || p.isEmpty) {
      throw StateError('Functions not configured: pass projectSlug to BackendClient.');
    }
    return '/f/${Uri.encodeComponent(p)}/${Uri.encodeComponent(slug)}';
  }

  /// Invoke a function. Only idempotent GETs may be retried — the SDK never
  /// retries POST/PUT/PATCH/DELETE automatically.
  Future<FunctionResponse<dynamic>> invoke(
    String slug, {
    String method = 'POST',
    Map<String, String>? query,
    Object? body,
    Map<String, String>? headers,
  }) async {
    final started = DateTime.now();
    final res = await _http.request(
      method,
      _path(slug),
      query: query,
      headers: headers,
      body: method.toUpperCase() == 'GET' ? null : (body ?? {}),
    );
    final data = res.data;
    final version = data is Map<String, dynamic> && data['version'] is int
        ? data['version'] as int
        : null;
    return FunctionResponse(
      data: data,
      status: res.status,
      requestId: res.requestId,
      durationMs: DateTime.now().difference(started).inMilliseconds,
      version: version,
    );
  }
}
