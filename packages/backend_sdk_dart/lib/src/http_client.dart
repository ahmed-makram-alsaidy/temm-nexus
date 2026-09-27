/// Fetch-based HTTP layer: auth injection, request IDs, timeouts, error mapping.
library;

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;

import 'api_error.dart';
import 'token_store.dart';

/// Builds `?filter[x]=&search=&sort=&page=&per_page=` per the contract.
Map<String, String> qs({
  int? page,
  int? perPage,
  String? search,
  Object? sort, // String or List<String>
  Map<String, Object>? filter,
}) {
  final out = <String, String>{};
  if (page != null) out['page'] = '$page';
  if (perPage != null) out['per_page'] = '$perPage';
  if (search != null && search.isNotEmpty) out['search'] = search;
  if (sort != null) out['sort'] = sort is List ? sort.join(',') : '$sort';
  filter?.forEach((k, v) => out['filter[$k]'] = '$v');
  return out;
}

String _newRequestId() {
  final r = Random.secure();
  final bytes = List<int>.generate(16, (_) => r.nextInt(256));
  final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return 'req_${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}_$hex';
}

class BackendHttp {
  final String baseUrl;
  String? apiKey;
  final bool apiKeyAsHeader;
  final Duration timeout;
  final http.Client inner;
  final TokenStore tokenStore;
  final Map<String, String> defaultHeaders;

  BackendHttp({
    required String baseUrl,
    this.apiKey,
    this.apiKeyAsHeader = false,
    this.timeout = const Duration(seconds: 15),
    http.Client? inner,
    TokenStore? tokenStore,
    Map<String, String>? defaultHeaders,
  })  : baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), ''),
        inner = inner ?? http.Client(),
        tokenStore = tokenStore ?? MemoryTokenStore(),
        defaultHeaders = {...?defaultHeaders} {
    if (baseUrl.isEmpty) throw ArgumentError('baseUrl is required');
  }

  /// Precedence (high → low): explicit token, explicit apiKey, stored token,
  /// configured apiKey. Explicit per-call credentials always override stored ones.
  Future<Map<String, String>> buildHeaders({
    Map<String, String>? explicit,
    String? token,
    String? key,
    bool tokenProvided = false,
    bool keyProvided = false,
  }) async {
    final headers = <String, String>{'Accept': 'application/json', ...defaultHeaders};
    final stored = (!tokenProvided && !keyProvided) ? await tokenStore.get() : null;
    final t = tokenProvided ? token : stored;
    if (t != null && t.isNotEmpty) {
      headers['Authorization'] = 'Bearer $t';
    } else {
      final k = keyProvided ? key : apiKey;
      if (k != null && k.isNotEmpty && !headers.containsKey('Authorization')) {
        if (apiKeyAsHeader) {
          headers['X-API-Key'] = k;
        } else {
          headers['Authorization'] = 'Bearer $k';
        }
      }
    }
    if (explicit != null) headers.addAll(explicit);
    return headers;
  }

  Future<({dynamic data, int status, Map<String, String> headers, String requestId})> request(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, String>? headers,
    Object? body,
    String? token,
    bool tokenProvided = false,
    String? key,
    bool keyProvided = false,
    Duration? timeoutOverride,
    String? requestId,
  }) async {
    final rid = requestId ?? _newRequestId();
    final base = '$baseUrl${path.startsWith('/') ? '' : '/'}$path';
    final uri = (query == null || query.isEmpty)
        ? Uri.parse(base)
        : Uri.parse(base).replace(queryParameters: query);
    final h = await buildHeaders(
      explicit: {'X-Request-ID': rid, ...?headers},
      token: token,
      tokenProvided: tokenProvided,
      key: key,
      keyProvided: keyProvided,
    );
    if (body != null) h.putIfAbsent('Content-Type', () => 'application/json');

    http.Response res;
    try {
      final t = timeoutOverride ?? timeout;
      final payload = body == null
          ? null
          : (body is String ? body : jsonEncode(body));
      res = await switch (method.toUpperCase()) {
        'GET' => inner.get(uri, headers: h).timeout(t),
        'POST' => inner.post(uri, headers: h, body: payload).timeout(t),
        'PUT' => inner.put(uri, headers: h, body: payload).timeout(t),
        'PATCH' => inner.patch(uri, headers: h, body: payload).timeout(t),
        'DELETE' => inner.delete(uri, headers: h, body: payload).timeout(t),
        _ => throw ArgumentError('Unsupported method $method'),
      };
    } on TimeoutException {
      throw BackendException(
        message: 'Request timed out after ${timeout.inMilliseconds}ms',
        code: BackendErrorCode.timeout,
        status: 0,
        requestId: rid,
      );
    } catch (e) {
      if (e is BackendException) rethrow;
      throw BackendException(
        message: e.toString(),
        code: BackendErrorCode.networkError,
        status: 0,
        requestId: rid,
      );
    }

    final responseId = res.headers['x-request-id'] ?? rid;
    final parsed = _parse(res);
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw toBackendException(
        status: res.statusCode,
        body: parsed,
        requestId: responseId,
        retryAfter: _retryAfter(res.headers),
      );
    }
    return (data: parsed, status: res.statusCode, headers: res.headers, requestId: responseId);
  }

  /// Multipart upload (field `file`). Timeouts default to 60s for uploads.
  Future<({dynamic data, int status, String requestId})> uploadMultipart({
    required String path,
    required List<int> bytes,
    required String fileName,
    String? mime,
    Map<String, String>? fields,
    Map<String, String>? headers,
    String? token,
    String? key,
    Duration timeoutOverride = const Duration(seconds: 60),
  }) async {
    final rid = _newRequestId();
    final uri = Uri.parse('$baseUrl${path.startsWith('/') ? '' : '/'}$path');
    final h = await buildHeaders(explicit: {'X-Request-ID': rid, ...?headers}, token: token, tokenProvided: token != null, key: key, keyProvided: key != null);
    h.remove('Content-Type'); // boundary is set by MultipartRequest
    final req = http.MultipartRequest('POST', uri)
      ..headers.addAll(h)
      ..fields.addAll({...?fields})
      ..files.add(http.MultipartFile.fromBytes('file', bytes, filename: fileName));
    if (mime != null) {
      req.files.clear();
      req.files.add(http.MultipartFile.fromBytes('file', bytes, filename: fileName));
    }
    http.StreamedResponse streamed;
    try {
      streamed = await inner.send(req).timeout(timeoutOverride);
    } on TimeoutException {
      throw BackendException(
        message: 'Upload timed out after ${timeoutOverride.inSeconds}s',
        code: BackendErrorCode.timeout,
        status: 0,
        requestId: rid,
      );
    } catch (e) {
      throw BackendException(message: e.toString(), code: BackendErrorCode.networkError, status: 0, requestId: rid);
    }
    final res = await http.Response.fromStream(streamed);
    final responseId = res.headers['x-request-id'] ?? rid;
    final parsed = _parse(res);
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw toBackendException(status: res.statusCode, body: parsed, requestId: responseId);
    }
    return (data: parsed, status: res.statusCode, requestId: responseId);
  }

  Object? _parse(http.Response res) {
    if (res.body.isEmpty) return null;
    try {
      return jsonDecode(res.body);
    } catch (_) {
      return res.body;
    }
  }

  Duration? _retryAfter(Map<String, String> headers) {
    final v = headers['retry-after'];
    if (v == null) return null;
    final s = int.tryParse(v.trim());
    if (s == null || s < 0) return null;
    return Duration(seconds: s);
  }
}
