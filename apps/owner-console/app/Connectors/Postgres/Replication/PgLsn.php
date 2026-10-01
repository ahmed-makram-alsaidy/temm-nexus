<?php

namespace App\Connectors\Postgres\Replication;

/**
 * Phase 35.6 — PostgreSQL WAL LSN arithmetic ("X/Y" hex pairs).
 *
 * LSNs are uint64 positions in the write-ahead log. PHP ints are 64-bit on
 * supported platforms, so an LSN is carried as int internally and rendered
 * as the canonical "XXXXXXXX/XXXXXXXX" string in checkpoints.
 */
final class PgLsn
{
    /** Parse "0/16B3748" (or "2/AB000000") into a 64-bit int. */
    public static function toInt(string $lsn): int
    {
        if (! preg_match('/^([0-9A-Fa-f]+)\/([0-9A-Fa-f]{1,8})$/', trim($lsn), $m)) {
            throw new \InvalidArgumentException("invalid PostgreSQL LSN '{$lsn}'");
        }
        $hi = (int) hexdec($m[1]);
        $lo = (int) hexdec($m[2]);

        return ($hi << 32) | $lo;
    }

    /** Render a 64-bit LSN int as the canonical PostgreSQL string form. */
    public static function toString(int $lsn): string
    {
        return sprintf('%X/%08X', ($lsn >> 32) & 0xFFFFFFFF, $lsn & 0xFFFFFFFF);
    }

    /** Validate; returns the canonical form (uppercase, 8 low digits). */
    public static function normalize(string $lsn): string
    {
        return self::toString(self::toInt($lsn));
    }

    /** True when $a is at or after $b (a >= b). */
    public static function atOrAfter(int $a, int $b): bool
    {
        return $a >= $b;
    }
}
