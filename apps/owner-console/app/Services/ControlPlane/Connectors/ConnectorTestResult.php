<?php

namespace App\Services\ControlPlane\Connectors;

/**
 * Phase 27B.1 — result of a connector connection test. Classification is
 * connector-provided but constrained to the stable result vocabulary so the
 * generic UI can present any connector identically.
 */
final class ConnectorTestResult
{
    public const PASS = 'PASS';
    public const INVALID_CREDENTIAL = 'INVALID_CREDENTIAL';
    public const INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';
    public const NOT_FOUND = 'NOT_FOUND';
    public const NETWORK_ERROR = 'NETWORK_ERROR';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const PROVIDER_ERROR = 'PROVIDER_ERROR';
    public const INVALID_CONFIGURATION = 'INVALID_CONFIGURATION';
    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    public const RESULTS = [
        self::PASS, self::INVALID_CREDENTIAL, self::INSUFFICIENT_SCOPE, self::NOT_FOUND,
        self::NETWORK_ERROR, self::RATE_LIMITED, self::PROVIDER_ERROR,
        self::INVALID_CONFIGURATION, self::UNKNOWN_ERROR,
    ];

    private function __construct(
        public readonly string $result,
        public readonly string $detail = '',
        public readonly array $metadata = [],
    ) {
    }

    public static function make(string $result, string $detail = '', array $metadata = []): self
    {
        if (! in_array($result, self::RESULTS, true)) {
            $result = self::UNKNOWN_ERROR;
        }

        return new self($result, mb_substr($detail, 0, 300), $metadata);
    }

    public static function pass(string $detail = '', array $metadata = []): self
    {
        return self::make(self::PASS, $detail, $metadata);
    }

    public function isPass(): bool
    {
        return $this->result === self::PASS;
    }

    public function toArray(): array
    {
        return ['result' => $this->result, 'detail' => $this->detail, 'metadata' => $this->metadata];
    }
}
