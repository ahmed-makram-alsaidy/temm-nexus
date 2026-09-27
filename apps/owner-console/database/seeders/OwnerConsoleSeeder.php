<?php

namespace Database\Seeders;

use App\Models\BackupRecord;
use App\Models\InfrastructureEvent;
use App\Models\Project;
use App\Models\User;
use App\Services\ControlPlane\CpAccess;
use Illuminate\Database\Seeder;

/**
 * OPTIONAL local demo seeder — disabled by default.
 *
 * A fresh, self-hosted installation must start EMPTY (no demo projects, no
 * default credentials). The first platform owner is created through the
 * first-run setup wizard at /setup, which enforces a strong password and
 * never stores a predictable one.
 *
 * To seed throwaway demo data for local exploration ONLY:
 *   ENABLE_DEMO_SEED=true php artisan db:seed
 * The generated admin password is random and printed exactly once.
 */
class OwnerConsoleSeeder extends Seeder
{
    public function run(): void
    {
        if (! env('ENABLE_DEMO_SEED')) {
            $this->command?->info('Skipped: set ENABLE_DEMO_SEED=true to seed local demo data. Fresh installs bootstrap via /setup.');

            return;
        }

        CpAccess::seedDefaults();

        // Random credential, never a documented default.
        $password = 'demo-'.bin2hex(random_bytes(12));

        $user = User::firstOrCreate(
            ['email' => 'demo-owner@localhost.test'],
            ['name' => 'Demo Owner', 'password' => $password, 'is_admin' => true]
        );
        User::where('email', 'demo-owner@localhost.test')->whereNull('cp_role')->update(['cp_role' => 'owner']);

        $this->command?->warn("Demo admin: demo-owner@localhost.test / {$password} (printed once — store it now)");

        foreach ([
            ['Demo Project A', 'demo-project-a', 'planned', 'demo_project_a_db', 'demoa', 'local', 'not_deployed'],
            ['Demo Project B', 'demo-project-b', 'planned', 'demo_project_b_db', 'demob', 'local', 'not_deployed'],
        ] as [$name, $slug, $status, $db, $prefix, $disk, $deploy]) {
            Project::firstOrCreate(['slug' => $slug], [
                'name' => $name, 'status' => $status,
                'domain' => null, 'api_domain' => "api.{$slug}.test",
                'db_name' => $db, 'redis_prefix' => $prefix,
                'storage_disk' => $disk, 'deploy_status' => $deploy,
            ]);
        }

        InfrastructureEvent::firstOrCreate(['message' => 'Local demo seed applied'], [
            'severity' => 'info', 'source' => 'seeder',
            'context' => ['mode' => 'demo'],
        ]);

        BackupRecord::firstOrCreate(['db_name' => 'demo_project_a_db'], [
            'status' => 'pending', 'restore_test_status' => 'not_tested',
        ]);
    }
}
