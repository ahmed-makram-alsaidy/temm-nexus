<?php

namespace App\Services\ControlPlane\Migration\Cdc;

/**
 * Phase 32A — one captured change, provider-agnostic.
 *
 * Events are UPSERTS or DELETES keyed by the target primary key — the
 * vocabulary any provider capture (log-based CDC, change streams, watermark
 * incremental export) normalizes into. The applier (32G) converges target
 * state regardless of event order or duplication.
 */
final class CdcEvent
{
    public const INSERT = 'insert';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public function __construct(
        public readonly string $table,
        public readonly string $op,
        /** @var array<string, mixed> row image (post-image for insert/update; PK-only for delete) */
        public readonly array $row,
        /** Provider-specific position marker this event was read at (ordering evidence, 32F). */
        public readonly ?string $position = null,
    ) {
    }

    public function isDelete(): bool
    {
        return $this->op === self::DELETE;
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
