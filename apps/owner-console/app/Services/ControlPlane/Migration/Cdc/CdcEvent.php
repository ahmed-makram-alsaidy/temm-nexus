<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 32A — one captured change, provider-agnostic.
 *
 * Events are UPSERTS or DELETES keyed by the target primary key — the
 * vocabulary any provider capture (log-based CDC, change streams, watermark
 * incremental export) normalizes into. The applier (32G) converges target
 * state regardless of event order or duplication.
 *
 * Phase 35.6 — the normalized envelope a LOG-BASED capture fills in: real
 * INSERT/UPDATE/DELETE operations, a before image where the log provides
 * one, the source commit timestamp, the source transaction identity and the
 * source position marker. The generic core never reads provider position
 * semantics (LSN / GTID / resume token) — it only carries the envelope.
 */
final class CdcEvent
{
    public const INSERT = 'insert';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public const OPERATIONS = [self::INSERT, self::UPDATE, self::DELETE];

    public function __construct(
        public readonly string $table,
        public readonly string $op,
        /** @var array<string, mixed> post-image (insert/update) or PK image (delete) */
        public readonly array $row,
        /** Provider-specific position marker this event was read at (ordering evidence, 32F). */
        public readonly ?string $position = null,
        /** Source schema/namespace, when the provider exposes one (35.6). */
        public readonly ?string $schema = null,
        /** @var array<string, mixed>|null before image where the log provides one (35.6). */
        public readonly ?array $before = null,
        /** Source commit/change timestamp as reported by the log (35.6). */
        public readonly ?string $sourceTimestamp = null,
        /** Source transaction identity where the provider exposes one (35.6). */
        public readonly ?string $transactionId = null,
        /** Monotonic capture sequence within the delivering batch (35.6). */
        public readonly int $sequence = 0,
    ) {
        if (! in_array($op, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException("unknown CDC operation '{$op}'");
        }
    }

    public function isDelete(): bool
    {
        return $this->op === self::DELETE;
    }

    /** Upserts (inserts AND updates) converge target state idempotently. */
    public function isUpsert(): bool
    {
        return $this->op !== self::DELETE;
    }

    public static function insert(string $table, array $row, ?string $position = null): self
    {
        return new self($table, self::INSERT, $row, $position);
    }

    public static function upsert(string $table, array $row, ?string $position = null): self
    {
        return new self($table, self::UPDATE, $row, $position);
    }

    public static function delete(string $table, array $pkRow, ?string $position = null): self
    {
        return new self($table, self::DELETE, $pkRow, $position);
    }
}
