<?php

declare(strict_types=1);

namespace Platform\BackendSdk;

/**
 * Stable SDK-side error. Never contains credential material or traces.
 *
 * NOTE (PHP): `Exception::$code` is taken (int, HTTP status via getCode()),
 * so the stable SDK code (e.g. `VALIDATION_ERROR`) lives on `$errorCode`
 * instead of `$code` (cf. JS `ApiError.code` / Dart `BackendException.code`).
 */
class ApiError extends \RuntimeException
{
    public readonly string $errorCode;
    public readonly int $status;
    public readonly ?array $errors;
    public readonly ?string $requestId;
    public readonly ?int $retryAfterMs;

    public function __construct(
        string $message,
        string $errorCode,
        int $status,
        ?array $errors = null,
        ?string $requestId = null,
        ?int $retryAfterMs = null,
    ) {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->errors = $errors;
        $this->requestId = $requestId;
        $this->retryAfterMs = $retryAfterMs;
    }

    public function isAuth(): bool
    {
        return $this->errorCode === 'UNAUTHENTICATED' || $this->errorCode === 'FORBIDDEN';
    }

    public static function codeForStatus(int $status, mixed $bodyCode = null): string
    {
        $known = ['BAD_REQUEST', 'UNAUTHENTICATED', 'FORBIDDEN', 'NOT_FOUND', 'CONFLICT',
            'VALIDATION_ERROR', 'RATE_LIMITED', 'SERVER_ERROR', 'UNAVAILABLE'];
        if (is_string($bodyCode) && in_array(strtoupper($bodyCode), $known, true)) {
            return strtoupper($bodyCode);
        }

        return match (true) {
            $status === 400 => 'BAD_REQUEST',
            $status === 401 => 'UNAUTHENTICATED',
            $status === 403 => 'FORBIDDEN',
            $status === 404 => 'NOT_FOUND',
            $status === 405 => 'METHOD_NOT_ALLOWED',
            $status === 409 => 'CONFLICT',
            $status === 422 => 'VALIDATION_ERROR',
            $status === 429 => 'RATE_LIMITED',
            $status === 503 => 'UNAVAILABLE',
            $status >= 500 => 'SERVER_ERROR',
            default => 'BAD_REQUEST',
        };
    }

    /** @param mixed $body already-decoded JSON (or raw string / null) */
    public static function fromHttp(int $status, mixed $body, ?string $requestId = null, ?int $retryAfterMs = null): self
    {
        $message = "Request failed with status {$status}";
        $errors = null;
        $code = null;
        if (is_array($body)) {
            if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
                $message = $body['message'];
            } elseif (isset($body['error']) && is_string($body['error']) && $body['error'] !== '') {
                $message = $body['error'];
            }
            if (isset($body['errors']) && is_array($body['errors'])) {
                $errors = $body['errors'];
            }
            $code = self::codeForStatus($status, $body['code'] ?? null);
        } elseif (is_string($body) && $body !== '') {
            $message = mb_substr($body, 0, 500);
            $code = self::codeForStatus($status);
        } else {
            $code = self::codeForStatus($status);
        }

        return new self($message, $code ?? self::codeForStatus($status), $status, $errors, $requestId, $retryAfterMs);
    }

    /** Redact credential headers before logging. */
    public static function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = in_array(strtolower((string) $k), ['authorization', 'x-api-key'], true) ? '[REDACTED]' : $v;
        }

        return $out;
    }
}
