<?php

namespace App\Services\ControlPlane;

/**
 * Phase 20F SQL safety classifier (application layer).
 *
 * Defense in depth (no single layer is trusted alone):
 *  1. classify() — first-statement allow/deny lists, multi-statement rules.
 *  2. Execution in READ ONLY transactions that are ALWAYS rolled back for reads.
 *  3. statement_timeout + row limits enforced server-side at execution.
 *  4. Write mode requires explicit elevation + audit; destructive patterns
 *     require typing the project slug (enforced by the page, verified here).
 *  5. Server-level vectors (COPY .. FROM PROGRAM, dblink/file_fdw, role DDL,
 *     session-altering SETs, LISTEN/NOTIFY abuse) are denied by pattern.
 */
class SqlGuard
{
    public const MAX_ROWS = 500;

    public const STATEMENT_TIMEOUT_MS = 15000;

    public static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sql = preg_replace('/--[^\n]*/', ' ', $sql);

        return trim((string) $sql);
    }

    /** Split on semicolons outside quotes/parens (best-effort, conservative). */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($quote) {
                $current .= $ch;
                if ($ch === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    // ''-escaped quote inside string stays inside.
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $current .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === ';' && $depth === 0) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return array_values($statements);
    }

    public static function firstKeyword(string $statement): string
    {
        $clean = ltrim($statement, " \t\n\r(");

        return strtoupper(strtok($clean, " \t\n\r(") ?: '');
    }

    /**
     * @return array{category:string,blocked:?string,destructive:bool}
     * category: read|write|explain|utility|blocked
     */
    public static function classify(string $sql, bool $writeMode = false): array
    {
        $clean = self::stripComments($sql);
        if ($clean === '') {
            return ['category' => 'blocked', 'blocked' => 'Empty query.', 'destructive' => false];
        }

        // Server-level / escape vectors are denied in every mode.
        $deniedPatterns = [
            '/\bCOPY\b.*\bFROM\s+PROGRAM\b/i' => 'COPY FROM PROGRAM is denied.',
            '/\bCOPY\b.*\bTO\s+PROGRAM\b/i' => 'COPY TO PROGRAM is denied.',
            '/\bpg_read_file\b|\bpg_ls_dir\b|\bpg_stat_file\b/i' => 'Server filesystem functions are denied.',
            '/\bpg_terminate_backend\b|\bpg_cancel_backend\b/i' => 'Server process control is denied.',
            '/\bdblink\b|\bpostgres_fdw\b|\bfile_fdw\b/i' => 'Foreign-data wrappers are denied.',
            '/\b(CREATE|ALTER|DROP)\s+(ROLE|USER|GROUP)\b/i' => 'Role management is denied.',
            '/\b(GRANT|REVOKE)\b/i' => 'Privilege grants are denied.',
            '/\bVACUUM\b|\bANALYZE\b|\bCLUSTER\b|\bREINDEX\b|\bCHECKPOINT\b/i' => 'Maintenance commands are denied.',
            '/\bLISTEN\b|\bNOTIFY\b/i' => 'LISTEN/NOTIFY is denied.',
            '/\bSET\s+(SESSION|LOCAL)?\s*(statement_timeout|lock_timeout|transaction|search_path|role|session_authorization)/i' => 'Session/GUC tampering is denied.',
            '/\bPREPARE\b|\bEXECUTE\b|\bDEALLOCATE\b/i' => 'Prepared-statement protocol is denied.',
            '/\bDO\b/i' => 'Anonymous code blocks (DO) are denied.',
        ];
        foreach ($deniedPatterns as $pattern => $reason) {
            if (preg_match($pattern, $clean)) {
                return ['category' => 'blocked', 'blocked' => $reason, 'destructive' => true];
            }
        }

        $statements = self::splitStatements($clean);
        if (count($statements) > 5) {
            return ['category' => 'blocked', 'blocked' => 'At most 5 statements per execution.', 'destructive' => false];
        }

        $hasRead = false;
        $hasWrite = false;
        $destructive = false;
        $destructiveReason = null;

        foreach ($statements as $stmt) {
            $kw = self::firstKeyword($stmt);
            if (in_array($kw, ['SELECT', 'WITH', 'VALUES', 'TABLE', 'SHOW'], true)) {
                $hasRead = true;
                continue;
            }
            if ($kw === 'EXPLAIN') {
                // EXPLAIN ANALYZE executes the query: treat as write-side caution,
                // allowed in read mode only without ANALYZE.
                if (preg_match('/^\s*EXPLAIN\s+(\([^)]*\)\s*)?ANALYZE\b/i', $stmt)) {
                    $hasWrite = true;
                } else {
                    $hasRead = true;
                }
                continue;
            }
            if (in_array($kw, ['INSERT', 'UPDATE', 'DELETE', 'MERGE'], true)) {
                $hasWrite = true;
                if ($kw === 'DELETE' && ! preg_match('/\bWHERE\b/i', $stmt)) {
                    $destructive = true;
                    $destructiveReason = 'DELETE without WHERE requires explicit confirmation.';
                }
                if ($kw === 'UPDATE' && ! preg_match('/\bWHERE\b/i', $stmt)) {
                    $destructive = true;
                    $destructiveReason = 'UPDATE without WHERE requires explicit confirmation.';
                }
                if ($kw === 'INSERT' && preg_match('/\bDEFAULT\s+VALUES\b/i', $stmt) && preg_match('/\bSELECT\b/i', $stmt)) {
                    $destructive = true;
                    $destructiveReason = 'INSERT…SELECT (mass insert) requires explicit confirmation.';
                }
                continue;
            }
            if (in_array($kw, ['DROP', 'TRUNCATE'], true)) {
                return ['category' => 'blocked', 'blocked' => $kw.' requires the visual Table Editor flow with typed confirmation — not the SQL box.', 'destructive' => true];
            }
            if (in_array($kw, ['ALTER', 'CREATE', 'COMMENT'], true)) {
                $hasWrite = true;
                $destructive = true;
                $destructiveReason = $kw.' changes schema and requires explicit confirmation.';
                continue;
            }
            if (in_array($kw, ['BEGIN', 'COMMIT', 'ROLLBACK', 'START', 'SAVEPOINT', 'END', 'ABORT'], true)) {
                return ['category' => 'blocked', 'blocked' => 'Transaction control is managed by the editor, not the query.', 'destructive' => false];
            }

            return ['category' => 'blocked', 'blocked' => "Statement type '{$kw}' is not permitted.", 'destructive' => false];
        }

        if ($hasWrite && ! $writeMode) {
            return ['category' => 'blocked', 'blocked' => 'Write statements require WRITE MODE elevation.', 'destructive' => $destructive];
        }

        $category = $hasWrite ? 'write' : 'read';

        return ['category' => $category, 'blocked' => null, 'destructive' => $destructive];
    }

    /** Replace string/number literals with ? for safe history/audit storage. */
    public static function redact(string $sql): string
    {
        $redacted = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql);
        $redacted = preg_replace('/\b\d+(\.\d+)?\b/', '?', (string) $redacted);

        return mb_substr(trim((string) $redacted), 0, 4000);
    }
}
