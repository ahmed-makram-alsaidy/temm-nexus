<?php

namespace App\Services\ControlPlane;

/**
 * GUI safety rails for the database browser. The normal GUI is NOT a generic
 * SQL console: destructive/structural operations stay in protected pgAdmin.
 */
class ProtectedTables
{
    /** Tables the GUI may never write to (read-only at most). */
    public const READ_ONLY = [
        'migrations',
        'failed_jobs',
        'job_batches',
        'jobs',
        'cache',
        'cache_locks',
        'sessions',
    ];

    /** Column names that must never be rendered or exported by the GUI. */
    public const SECRET_COLUMNS = [
        'password',
        'password_hash',
        'remember_token',
        'api_key',
        'secret',
        'client_secret',
        'private_key',
        'db_password',
    ];

    public static function isReadOnly(string $table): bool
    {
        return in_array(strtolower($table), self::READ_ONLY, true);
    }

    public static function visibleColumns(array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn ($c) => ! in_array(strtolower($c), self::SECRET_COLUMNS, true)
        ));
    }
}
