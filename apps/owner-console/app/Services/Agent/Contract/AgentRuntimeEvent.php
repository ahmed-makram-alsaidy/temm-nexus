<?php

namespace App\Services\Agent\Contract;

/**
 * One normalized runtime event, safe for persistence and display.
 *
 * `payload` carries bounded METADATA only (paths, command strings, exit
 * codes, byte sizes, truncation flags). It must never contain raw command
 * output, provider credentials, or hidden chain-of-thought content.
 */
final class AgentRuntimeEvent
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $summary = null,
        public readonly array $payload = [],
        public readonly ?string $sessionId = null,
    ) {
    }

    public function withSession(string $sessionId): self
    {
        return new self($this->type, $this->summary, $this->payload, $sessionId);
    }
}
