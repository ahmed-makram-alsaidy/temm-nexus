import type { ApiErrorCode } from './types.js';

/** Stable SDK-side error. Never contains Authorization material or traces. */
export class ApiError extends Error {
  readonly code: ApiErrorCode;
  readonly status: number;
  readonly errors?: Record<string, string[]>;
  readonly requestId?: string;
  readonly retryAfterMs?: number;

  constructor(opts: {
    message: string;
    code: ApiErrorCode;
    status: number;
    errors?: Record<string, string[]>;
    requestId?: string;
    retryAfterMs?: number;
  }) {
    super(opts.message);
    this.name = 'ApiError';
    this.code = opts.code;
    this.status = opts.status;
    this.errors = opts.errors;
    this.requestId = opts.requestId;
    this.retryAfterMs = opts.retryAfterMs;
  }

  isAuth(): boolean {
    return this.code === 'UNAUTHENTICATED' || this.code === 'FORBIDDEN';
  }

  isRetryableGet(): boolean {
    return this.code === 'RATE_LIMITED';
  }
}

function codeForStatus(status: number, bodyCode?: unknown): ApiErrorCode {
  if (typeof bodyCode === 'string') {
    const upper = bodyCode.toUpperCase();
    const known: ApiErrorCode[] = [
      'BAD_REQUEST', 'UNAUTHENTICATED', 'FORBIDDEN', 'NOT_FOUND', 'CONFLICT',
      'VALIDATION_ERROR', 'RATE_LIMITED', 'SERVER_ERROR', 'UNAVAILABLE',
    ];
    if (known.includes(upper as ApiErrorCode)) return upper as ApiErrorCode;
  }
  switch (status) {
    case 400: return 'BAD_REQUEST';
    case 401: return 'UNAUTHENTICATED';
    case 403: return 'FORBIDDEN';
    case 404: return 'NOT_FOUND';
    case 405: return 'METHOD_NOT_ALLOWED';
    case 409: return 'CONFLICT';
    case 422: return 'VALIDATION_ERROR';
    case 429: return 'RATE_LIMITED';
    case 503: return 'UNAVAILABLE';
    case 500:
    default:
      return status >= 500 ? 'SERVER_ERROR' : 'BAD_REQUEST';
  }
}

/** Build an ApiError from an HTTP failure. `body` is already parsed (or null). */
export function toApiError(args: {
  status: number;
  body: unknown;
  requestId?: string;
  retryAfterMs?: number;
}): ApiError {
  const { status, body, requestId, retryAfterMs } = args;
  let message = `Request failed with status ${status}`;
  let errors: Record<string, string[]> | undefined;
  let code: ApiErrorCode | undefined;
  if (body && typeof body === 'object') {
    const b = body as Record<string, unknown>;
    if (typeof b['message'] === 'string' && b['message'] !== '') message = b['message'];
    else if (typeof b['error'] === 'string' && b['error'] !== '') message = b['error'];
    if (b['errors'] && typeof b['errors'] === 'object') {
      errors = b['errors'] as Record<string, string[]>;
    }
    code = codeForStatus(status, b['code']);
  } else if (typeof body === 'string' && body !== '') {
    message = body.slice(0, 500);
    code = codeForStatus(status);
  } else {
    code = codeForStatus(status);
  }
  return new ApiError({ message, code: code ?? codeForStatus(status), status, errors, requestId, retryAfterMs });
}

/** Redact anything that looks like credential material for logs/errors. */
export function redactHeaders(headers: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(headers)) {
    out[k] = /^(authorization|x-api-key)$/i.test(k) ? '[REDACTED]' : v;
  }
  return out;
}
