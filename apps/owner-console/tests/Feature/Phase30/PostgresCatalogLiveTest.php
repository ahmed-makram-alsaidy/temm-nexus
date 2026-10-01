<?php

namespace Tests\Feature\Phase30;

use App\Services\ControlPlane\Connectors\ConnectorCredentials;
use App\Connectors\Postgres\Protocol\PdoPgExecutor;
use App\Connectors\Postgres\PostgresCatalog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 35.5 live-verification regression: PostgresCatalog SQL must execute
 * against a REAL PostgreSQL server. Found live on PostgreSQL 17: the large
 * object inventory queried `pg_largeobject_metadata.loid`, a column that no
 * longer exists (it is `oid`) — the fixture executor never executes real
 * SQL, so the bug only surfaced against a live server.
 *
 * Self-skipping unless a live server is provided via env (the repository's
 * documented convention for private/environment-bound fixtures):
 *   POSTGRES_LIVE_HOST / POSTGRES_LIVE_PORT / POSTGRES_LIVE_DB /
 *   POSTGRES_LIVE_USER / POSTGRES_LIVE_PASSWORD
 */
class PostgresCatalogLiveTest extends TestCase
{
    private bool $configured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) env('POSTGRES_LIVE_HOST');
        if ($host === '') {
            $this->markTestSkipped('no live PostgreSQL configured (set POSTGRES_LIVE_*)');
        }
        $this->configured = true;
    }

    public function test_catalog_inventory_executes_against_live_server(): void
    {
        $executor = PdoPgExecutor::forSource(
            [
                'host' => (string) env('POSTGRES_LIVE_HOST'),
                'port' => (int) env('POSTGRES_LIVE_PORT', 5432),
                'database' => (string) env('POSTGRES_LIVE_DB', 'postgres'),
                'ssl_mode' => 'disable',
            ],
            (string) env('POSTGRES_LIVE_USER', 'postgres'),
            (string) env('POSTGRES_LIVE_PASSWORD', ''),
            true,
        );
        $catalog = new PostgresCatalog($executor);

        // System schemas must never leak into the schema list (35.5: the
        // broken pg_ LIKE pattern let pg_catalog through, whose aggregates
        // then crashed pg_get_functiondef).
        foreach ($catalog->schemas() as $schema) {
            $this->assertStringNotStartsWith('pg_', $schema);
            $this->assertNotSame('information_schema', $schema);
        }

        // The exact query that failed live (pg_largeobject_metadata column).
        $largeObjects = $catalog->largeObjects();
        $this->assertArrayHasKey('count', $largeObjects);
        $this->assertArrayHasKey('bytes', $largeObjects);

        // Full inventory walk must not throw on any system catalog query.
        foreach ($catalog->schemas() as $schema) {
            foreach ($catalog->tables($schema) as $table) {
                $name = $table['relname'] ?? $table['table_name'] ?? null;
                if ($name === null) {
                    continue;
                }
                $catalog->columns($schema, (string) $name);
                $catalog->primaryKey($schema, (string) $name);
                $catalog->foreignKeys($schema, (string) $name);
                $catalog->indexes($schema, (string) $name);
            }
            $catalog->views($schema);
            $catalog->sequences($schema);
            $catalog->functions($schema);
            $catalog->triggers($schema);
            $catalog->enums($schema);
            $catalog->domains($schema);
        }

        $this->assertTrue($this->configured);
    }
}
