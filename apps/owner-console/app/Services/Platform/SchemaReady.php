<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\Schema;

/**
 * Safe schema probes used before/while the platform database may not exist
 * yet (first boot). Never throws — a missing table simply means "false".
 */
class SchemaReady
{
    /** @param list<string> $tables */
    public static function tables(array $tables): bool
    {
        foreach ($tables as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
