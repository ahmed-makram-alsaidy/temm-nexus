<?php

namespace App\Connectors\Firebase\Protocol;

/**
 * Transport-level failure carrying an honest classification for the
 * connector surfaces (29B): HTTP status (or 0 for network errors) and a
 * SANITIZED message — response bodies can echo request data, so the raw
 * body is never embedded here.
 */
class FirebaseTransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
    ) {
        parent::__construct($message);
    }

    public function isAuthFailure(): bool
    {
        return in_array($this->statusCode, [401, 403], true);
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }
}
