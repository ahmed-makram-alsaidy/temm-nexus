<?php

namespace App\Services\ControlPlane;

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 20F SQL execution engine.
 *
 * Safety contract:
 * - classify() runs first; blocked statements never reach the database.
 * - READ queries run inside a transaction with TRANSACTION READ ONLY and are
 *   ALWAYS rolled back — even a classifier bypass cannot persist writes.
 * - EXPLAIN runs without ANALYZE in read mode (ANALYZE needs write mode).
 * - statement_timeout + lock_timeout bound every execution; rows capped.
 * - Only the project's own scoped connection is ever used (no superuser,
 *   no cross-database access — the connection string fixes one database).
 */
class SqlRunner
{
    public static function run(Project $project, string $sql, bool $writeMode = false): array
    {
        $started = microtime(true);
        $classification = SqlGuard::classify($sql, $writeMode);
        if ($classification['blocked'] !== null) {
            return self::result($started, $classification, [], 0, 'blocked', $classification['blocked']);
        }

        $connection = ProjectConnectionManager::connection($project);
        $db = DB::connection($connection);
        $statements = SqlGuard::splitStatements(SqlGuard::stripComments($sql));
        $category = $classification['category'];

        try {
            if ($category === 'read') {
                $out = $db->transaction(function () use ($db, $statements) {
                    // READ ONLY first: SET TRANSACTION must precede any other
                    // statement in the transaction.
                    $db->statement('SET TRANSACTION READ ONLY');
                    $db->statement('SET LOCAL statement_timeout = '.((int) SqlGuard::STATEMENT_TIMEOUT_MS));
                    $db->statement('SET LOCAL lock_timeout = 5000');
                    $rows = [];
                    $count = 0;
                    foreach ($statements as $stmt) {
                        if (SqlGuard::firstKeyword($stmt) === 'EXPLAIN') {
                            $rows = array_merge($rows, self::rowsToArrays($db->select($stmt)));
                            continue;
                        }
                        foreach (self::rowsToArrays($db->select($stmt)) as $row) {
                            $rows[] = $row;
                            if (++$count >= SqlGuard::MAX_ROWS) {
                                break 2;
                            }
                        }
                    }
                    // Read-only guarantee: never commit, even on success.
                    throw new ReadOnlyRollback($rows);
                });
            } else {
                // Write mode: explicit transaction, committed only on success.
                $affected = 0;
                $db->transaction(function () use ($db, $statements, &$affected) {
                    $db->statement('SET LOCAL statement_timeout = '.((int) SqlGuard::STATEMENT_TIMEOUT_MS));
                    $db->statement('SET LOCAL lock_timeout = 5000');
                    foreach ($statements as $stmt) {
                        $affected += $db->affectingStatement($stmt);
                    }
                });
                $out = [];
            }

            return self::result($started, $classification, $out ?? [], $affected ?? 0, 'ok');
        } catch (ReadOnlyRollback $e) {
            return self::result($started, $classification, $e->rows, count($e->rows), 'ok', null, count($statements) > 1 || count($e->rows) >= SqlGuard::MAX_ROWS);
        } catch (\Illuminate\Database\QueryException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'statement timeout') || str_contains($message, 'canceling statement due to statement timeout')
                ? 'timeout' : 'error';

            return self::result($started, $classification, [], 0, $status, self::safeError($message));
        } catch (\Throwable $e) {
            return self::result($started, $classification, [], 0, 'error', self::safeError($e->getMessage()));
        }
    }

    /** @return list<array<string,mixed>> */
    public static function rowsToArrays(array $rows): array
    {
        return array_map(fn ($r) => (array) $r, $rows);
    }

    protected static function result(
        float $started,
        array $classification,
        array $rows,
        int $affected,
        string $status,
        ?string $error = null,
        bool $truncated = false
    ): array {
        return [
            'category' => $classification['category'],
            'destructive' => $classification['destructive'],
            'blocked' => $classification['blocked'],
            'rows' => array_slice($rows, 0, SqlGuard::MAX_ROWS),
            'columns' => $rows ? array_keys($rows[0]) : [],
            'affected' => $affected,
            'truncated' => $truncated || count($rows) >= SqlGuard::MAX_ROWS,
            'status' => $status,
            'error' => $error,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ];
    }

    /** Strip connection details/paths from driver errors before display. */
    public static function safeError(string $message): string
    {
        $message = preg_replace('/(host|password|user|dbname)=[^\s;]+/i', '$1=***', $message);
        $message = preg_replace('#/[\w\-./]+/(projects|var|srv|etc)/[\w\-./]*#', '[path]', $message);

        return mb_substr(trim($message), 0, 800);
    }

    public static function requestId(): string
    {
        return Str::uuid()->toString();
    }
}
