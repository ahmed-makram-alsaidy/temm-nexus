/// Stable SDK-side error. Never contains credential material or traces.
library;

/// Stable error codes. Mirrors the TypeScript SDK `ApiErrorCode`.
abstract final class BackendErrorCode {
  static const badRequest = 'BAD_REQUEST';
  static const unauthenticated = 'UNAUTHENTICATED';
  static const forbidden = 'FORBIDDEN';
  static const notFound = 'NOT_FOUND';
  static const methodNotAllowed = 'METHOD_NOT_ALLOWED';
  static const conflict = 'CONFLICT';
  static const validationError = 'VALIDATION_ERROR';
  static const rateLimited = 'RATE_LIMITED';
  static const serverError = 'SERVER_ERROR';
  static const unavailable = 'UNAVAILABLE';
  static const networkError = 'NETWORK_ERROR';
  static const timeout = 'TIMEOUT';
  static const aborted = 'ABORTED';
}

class BackendException implements Exception {
  final String message;
  final String code;
  final int status;
  final Map<String, List<String>>? errors;
  final String? requestId;
  final Duration? retryAfter;

  const BackendException({
    required this.message,
    required this.code,
    required this.status,
    this.errors,
    this.requestId,
    this.retryAfter,
  });

  bool get isAuth =>
      code == BackendErrorCode.unauthenticated || code == BackendErrorCode.forbidden;

  @override
  String toString() => 'BackendException($code, HTTP $status): $message'
      '${requestId != null ? ' [request $requestId]' : ''}';
}

String _codeForStatus(int status, Object? bodyCode) {
  const known = {
    'BAD_REQUEST',
    'UNAUTHENTICATED',
    'FORBIDDEN',
    'NOT_FOUND',
    'CONFLICT',
    'VALIDATION_ERROR',
    'RATE_LIMITED',
    'SERVER_ERROR',
    'UNAVAILABLE',
  };
  if (bodyCode is String && known.contains(bodyCode.toUpperCase())) {
    return bodyCode.toUpperCase();
  }
  return switch (status) {
    400 => BackendErrorCode.badRequest,
    401 => BackendErrorCode.unauthenticated,
    403 => BackendErrorCode.forbidden,
    404 => BackendErrorCode.notFound,
    405 => BackendErrorCode.methodNotAllowed,
    409 => BackendErrorCode.conflict,
    422 => BackendErrorCode.validationError,
    429 => BackendErrorCode.rateLimited,
    503 => BackendErrorCode.unavailable,
    _ => status >= 500 ? BackendErrorCode.serverError : BackendErrorCode.badRequest,
  };
}

/// Normalize an HTTP failure to a [BackendException].
BackendException toBackendException({
  required int status,
  required Object? body,
  String? requestId,
  Duration? retryAfter,
}) {
  var message = 'Request failed with status $status';
  Map<String, List<String>>? errors;
  String? code;
  if (body is Map<String, dynamic>) {
    final m = body['message'];
    final e = body['error'];
    if (m is String && m.isNotEmpty) {
      message = m;
    } else if (e is String && e.isNotEmpty) {
      message = e;
    }
    final rawErrors = body['errors'];
    if (rawErrors is Map) {
      errors = {
        for (final entry in rawErrors.entries)
          entry.key.toString(): (entry.value as List).map((v) => v.toString()).toList(),
      };
    }
    code = _codeForStatus(status, body['code']);
  } else if (body is String && body.isNotEmpty) {
    message = body.length > 500 ? body.substring(0, 500) : body;
    code = _codeForStatus(status, null);
  }
  return BackendException(
    message: message,
    code: code ?? _codeForStatus(status, null),
    status: status,
    errors: errors,
    requestId: requestId,
    retryAfter: retryAfter,
  );
}

/// Redact credential headers before logging.
Map<String, String> redactHeaders(Map<String, String> headers) => {
      for (final e in headers.entries)
        e.key: (e.key.toLowerCase() == 'authorization' || e.key.toLowerCase() == 'x-api-key')
            ? '[REDACTED]'
            : e.value,
    };
