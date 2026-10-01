<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 35.6 — normalized CDC stream telemetry persisted beside a
 * checkpoint. GENERIC fields only: the Cutover Center reads this shape
 * without ever learning LSN / GTID / resume-token semantics. Provider
 * detail (display-only labels) lives under 'detail'.
 */
final class CdcStreamTelemetry
{
    public const ACTIVE = 'active';
    public const CAUGHT_UP = 'caught_up';
    public const IDLE = 'idle';
    public const DISCONNECTED = 'disconnected';
    public const ERROR = 'error';

    public const STATUSES = [self::ACTIVE, self::CAUGHT_UP, self::IDLE, self::DISCONNECTED, self::ERROR];

    public function __construct(
        public readonly string $status,
        public readonly ?string $sourcePositionLabel = null,
        public readonly ?string $capturedPositionLabel = null,
        public readonly ?string $appliedPositionLabel = null,
        public readonly ?float $lagSeconds = null,
        public readonly ?int $lagEvents = null,
        public readonly ?string $lastEventAt = null,
        public readonly ?string $mechanism = null,
        /** @var array<string, mixed> display-only provider detail */
        public readonly array $detail = [],
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("unknown CDC stream status '{$status}'");
        }
    }

    public static function fromLagSnapshot(array $snapshot): self
    {
        return new self(
            status: (string) ($snapshot['status'] ?? self::IDLE),
            sourcePositionLabel: isset($snapshot['source_position_label']) ? (string) $snapshot['source_position_label'] : null,
            capturedPositionLabel: isset($snapshot['captured_position_label']) ? (string) $snapshot['captured_position_label'] : null,
            appliedPositionLabel: isset($snapshot['applied_position_label']) ? (string) $snapshot['applied_position_label'] : null,
            lagSeconds: isset($snapshot['lag_seconds']) ? (float) $snapshot['lag_seconds'] : null,
            lagEvents: isset($snapshot['lag_events']) ? (int) $snapshot['lag_events'] : null,
            lastEventAt: isset($snapshot['last_event_at']) ? (string) $snapshot['last_event_at'] : null,
            mechanism: isset($snapshot['mechanism']) ? (string) $snapshot['mechanism'] : null,
            detail: is_array($snapshot['detail'] ?? null) ? $snapshot['detail'] : [],
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'source_position_label' => $this->sourcePositionLabel,
            'captured_position_label' => $this->capturedPositionLabel,
            'applied_position_label' => $this->appliedPositionLabel,
            'lag_seconds' => $this->lagSeconds,
            'lag_events' => $this->lagEvents,
            'last_event_at' => $this->lastEventAt,
            'mechanism' => $this->mechanism,
            'detail' => $this->detail,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public static function fromArray(array $value): self
    {
        return new self(
            status: (string) ($value['status'] ?? self::IDLE),
            sourcePositionLabel: $value['source_position_label'] ?? null,
            capturedPositionLabel: $value['captured_position_label'] ?? null,
            appliedPositionLabel: $value['applied_position_label'] ?? null,
            lagSeconds: isset($value['lag_seconds']) ? (float) $value['lag_seconds'] : null,
            lagEvents: isset($value['lag_events']) ? (int) $value['lag_events'] : null,
            lastEventAt: $value['last_event_at'] ?? null,
            mechanism: $value['mechanism'] ?? null,
            detail: is_array($value['detail'] ?? null) ? $value['detail'] : [],
        );
    }
}
