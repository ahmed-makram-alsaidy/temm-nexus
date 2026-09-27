<?php

namespace App\Connectors\Mongodb\Protocol;

/**
 * Phase 28 — a MongoDB command returned an error. Carries the server error
 * code for classification; messages never contain the connection URI.
 */
class MongoCommandException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0)
    {
        parent::__construct($message, $code);
    }

    /** The server error code (RuntimeException::$code is protected). */
    public function serverCode(): int
    {
        return $this->code;
    }
}
