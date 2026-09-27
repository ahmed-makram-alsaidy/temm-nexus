<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27Q.3 — connector instance health, reported generically by every
 * connector. Statuses are stable so the core UI can render any connector.
 */
final class ConnectorHealth
{
    public const CONNECTED = 'CONNECTED';
    public const PARTIAL = 'PARTIAL';
    public const DISCONNECTED = 'DISCONNECTED';
    public const ERROR = 'ERROR';
    public const DISABLED = 'DISABLED';

    public const STATUSES = [
        self::CONNECTED, self::PARTIAL, self::DISCONNECTED, self::ERROR, self::DISABLED,
    ];

    private function __construct(
        public readonly string $status,
        public readonly string $detail = '',
        public readonly array $checks = [],
    ) {
    }

    public static function make(string $status, string $detail = '', array $checks = []): self
    {
        if (! in_array($status, self::STATUSES, true)) {
            $status = self::ERROR;
        }

        return new self($status, mb_substr($detail, 0, 300), $checks);
    }

    public static function connected(string $detail = '', array $checks = []): self
    {
        return self::make(self::CONNECTED, $detail, $checks);
    }

    public function toArray(): array
    {
        return ['status' => $this->status, 'detail' => $this->detail, 'checks' => $this->checks];
    }
}
