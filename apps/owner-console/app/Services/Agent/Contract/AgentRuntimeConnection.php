<?php

namespace App\Services\Agent\Contract;

/** Result of a runtime connection test — classified, safe to display. */
final class AgentRuntimeConnection
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $version = null,
        public readonly ?string $message = null,   // human-safe classification, no wire dumps
        public readonly ?string $errorCategory = null, // AgentRuntimeException::* when ! ok
    ) {
    }

    public static function connected(string $version): self
    {
        return new self(true, version: $version);
    }

    public static function failed(string $errorCategory, string $message): self
    {
        return new self(false, message: $message, errorCategory: $errorCategory);
    }
}
