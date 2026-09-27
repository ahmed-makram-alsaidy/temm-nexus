<?php

namespace App\Connectors\Supabase;

/**
 * Phase 25C.2 — application-level read-only guard for source connections.
 *
 * Complements (never replaces) DB-level read-only
 * (`SET default_transaction_read_only = on` in the adapters). Every SQL
 * statement destined for a SOURCE connection must pass this guard.
 */
class SourceWriteGuard
{
    public const BLOCKED_VERBS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'CREATE', 'ALTER', 'DROP',
        'TRUNCATE', 'GRANT', 'REVOKE',
    ];

    /** Throw unless the statement is provably read-only. */
    public static function assertReadOnly(string $sql): void
    {
        // Strip comments/strings so keyword matches inside literals don't fool us.
        $stripped = preg_replace("/\/\*.*?\*\//s", ' ', $sql);
        $stripped = preg_replace("/--.*$/m", ' ', $stripped);
        $stripped = preg_replace("/'(?:[^']|'')*'/", '?', $stripped);

        if (preg_match('/\b(' . implode('|', self::BLOCKED_VERBS) . ')\b/i', $stripped, $m)) {
            abort(422, "Source connection is READ-ONLY — {$m[1]} is not permitted.");
        }
        if (preg_match('/\b(CALL|DO|VACUUM|REINDEX|LISTEN|NOTIFY|COPY)\b/i', $stripped)) {
            abort(422, 'Source connection permits SELECT/EXPLAIN metadata queries only.');
        }
    }

    /** True when the statement passed the guard (for probing paths). */
    public static function isReadOnly(string $sql): bool
    {
        try {
            self::assertReadOnly($sql);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
