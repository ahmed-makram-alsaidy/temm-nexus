<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Live-proof tests against the disposable control-plane-demo project.
 * Per 18T the demo is removed after verification; these tests skip cleanly
 * when its database is absent. Re-run scripts/e2e-18s.sh for live proof.
 */
trait RequiresDemoDatabase
{
    protected function requireDemoDatabase(): void
    {
        try {
            $exists = DB::connection('pgsql-monitor')->selectOne(
                "SELECT 1 AS ok FROM pg_database WHERE datname = 'control_plane_demo_db'"
            );
        } catch (\Throwable) {
            $exists = null;
        }
        if ($exists === null) {
            $this->markTestSkipped('control-plane-demo database absent (removed per 18T).');
        }
    }
}
