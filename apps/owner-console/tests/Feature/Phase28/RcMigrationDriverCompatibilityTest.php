<?php

namespace Tests\Feature\Phase28;

use Tests\TestCase;

/**
 * Phase 28.1 — fresh-install regression guard (28.1C finding).
 *
 * The 0.2.0-rc.1 artifact-only fresh install failed on PostgreSQL: the
 * Phase 27 migration backfilled `connector_key` with DB::raw('`type`') —
 * MySQL backtick quoting is a syntax error on PostgreSQL. The suite stayed
 * green because tests run on SQLite, which accepts backticks.
 *
 * Guard: any migration that quotes an identifier inside DB::raw() must
 * branch on the connection driver (or scope to MySQL drivers explicitly)
 * so it stays portable across the supported runtimes.
 */
class RcMigrationDriverCompatibilityTest extends TestCase
{
    public function test_migrations_never_use_unscoped_backtick_identifiers_in_raw_sql(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        $this->assertNotEmpty($files, 'no migration files found — guard could not run');

        $violations = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $usesRawBacktick = preg_match("/DB::raw\(\s*['\"]`/", $source) === 1;
            $hasDriverGuard = preg_match('/getDriverName|\'mariadb\',\s*\'mysql\'|\"mariadb\",\s*\"mysql\"/', $source) === 1;

            if ($usesRawBacktick && ! $hasDriverGuard) {
                $violations[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Migrations quote MySQL backticks inside DB::raw() without a driver guard — '.
            'they pass on SQLite tests but are syntax errors on a fresh PostgreSQL install.'
        );
    }
}
