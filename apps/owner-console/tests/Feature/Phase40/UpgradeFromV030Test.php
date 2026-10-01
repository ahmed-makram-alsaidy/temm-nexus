<?php

namespace Tests\Feature\Phase40;

use App\Models\Project;
use App\Models\User;
use App\Services\Access\Access;
use App\Services\Access\Capability;
use Filament\Panel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 0.4.0 §36 — upgrade from 0.3.0.
 *
 * This does NOT merely run migrations on a fresh database. It reconstructs the
 * PRE-0.4.0 shape of `users` and `projects` (no workspace tables, no
 * platform_role, no workspace_id), inserts representative 0.3.0 rows, and only
 * THEN runs the 0.4.0 workspace migration — proving the real upgrade path that
 * an existing installation will take.
 *
 * The promise under test: an upgrade must not lose projects, users, settings,
 * connector rows, migration history, CDC checkpoints, backups, or audit
 * history, and must not lock existing operators out.
 */
class UpgradeFromV030Test extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_02_000000_phase40_workspaces.php');
    }

    /**
     * Rebuild the 0.3.0 world: drop everything 0.4.0 added, then insert rows the
     * way Phase 20V/24/25 code would have.
     */
    private function rewindToV030(): void
    {
        Schema::dropIfExists('user_ui_preferences');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');

        if (Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->dropIndex(['workspace_id']);
                $t->dropColumn('workspace_id');
            });
        }

        if (Schema::hasColumn('users', 'platform_role')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn(['platform_role', 'job_title']);
            });
        }
    }

    /** Seed a realistic 0.3.0 installation. */
    private function seedV030Data(): void
    {
        $now = now();

        // Users exactly as Phase 20V wrote them.
        DB::table('users')->insert([
            ['name' => 'Platform Owner', 'email' => 'owner@legacy.test', 'password' => bcrypt('x'), 'is_admin' => true,  'cp_role' => 'owner',     'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ops Admin',      'email' => 'admin@legacy.test', 'password' => bcrypt('x'), 'is_admin' => false, 'cp_role' => 'admin',     'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Dev',            'email' => 'dev@legacy.test',   'password' => bcrypt('x'), 'is_admin' => false, 'cp_role' => 'developer', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Observer',       'email' => 'obs@legacy.test',   'password' => bcrypt('x'), 'is_admin' => false, 'cp_role' => 'observer',  'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Unassigned',     'email' => 'none@legacy.test',  'password' => bcrypt('x'), 'is_admin' => false, 'cp_role' => null,        'created_at' => $now, 'updated_at' => $now],
        ]);

        // Projects exactly as Phase 20/24 wrote them (no workspace_id column).
        DB::table('projects')->insert([
            ['name' => 'Wasla', 'slug' => 'wasla', 'status' => 'active', 'storage_disk' => 'local', 'deploy_status' => 'not_deployed', 'environment' => 'local', 'timezone' => 'UTC', 'locale' => 'en', 'maintenance_mode' => 0, 'health_status' => 'healthy', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Nayrouz', 'slug' => 'nayrouz', 'status' => 'active', 'storage_disk' => 'local', 'deploy_status' => 'not_deployed', 'environment' => 'local', 'timezone' => 'UTC', 'locale' => 'en', 'maintenance_mode' => 0, 'health_status' => 'unknown', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $projectA = DB::table('projects')->where('slug', 'wasla')->value('id');
        $projectB = DB::table('projects')->where('slug', 'nayrouz')->value('id');

        // History that MUST survive the upgrade, each hanging off project id.
        DB::table('project_environments')->insert([
            [
                'project_id' => $projectA, 'name' => 'Local', 'slug' => 'local',
                'type' => 'local', 'status' => 'active', 'is_default' => 1, 'disposable' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        DB::table('audit_logs')->insert([
            ['auditable_type' => Project::class, 'auditable_id' => $projectA, 'event' => 'phase20.legacy', 'actor_id' => 1, 'changes' => '{"a":1}', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('backup_records')->insert([
            [
                'db_name' => 'wasla_db', 'status' => 'completed', 'type' => 'full',
                'location' => 'local', 'restore_test_status' => 'not_tested',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        if (Schema::hasTable('cdc_checkpoints')) {
            // Walk the real 0.3.0 chain so the checkpoint has a legitimate
            // parent: source -> analysis -> plan -> run -> checkpoint.
            $sourceId = DB::table('migration_sources')->insertGetId([
                'project_id' => $projectA, 'display_name' => 'Legacy MySQL',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $analysisId = DB::table('migration_analyses')->insertGetId([
                'project_id' => $projectA, 'migration_source_id' => $sourceId,
                'run_id' => 'analysis-legacy-'.uniqid(),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $planId = DB::table('migration_plans')->insertGetId([
                'project_id' => $projectA, 'migration_analysis_id' => $analysisId,
                'name' => 'Legacy Plan', 'created_at' => $now, 'updated_at' => $now,
            ]);

            $runId = DB::table('migration_runs')->insertGetId([
                'project_id' => $projectA, 'migration_plan_id' => $planId,
                'run_id' => 'run-legacy-'.uniqid(), 'mode' => 'full',
                'status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('cdc_checkpoints')->insert([
                [
                    'migration_run_id' => $runId, 'source_type' => 'mysql',
                    'target_key' => 'default', 'kind' => 'gtid',
                    'position' => '12345', 'signature' => 'legacy-signature',
                    'applied_events' => 7, 'created_at' => $now, 'updated_at' => $now,
                ],
            ]);
        }
    }

    private function snapshot(): array
    {
        $tables = ['users', 'projects', 'project_environments', 'audit_logs', 'backup_records'];
        if (Schema::hasTable('cdc_checkpoints')) {
            $tables[] = 'cdc_checkpoints';
        }

        $out = [];
        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────

    public function test_upgrade_preserves_every_existing_row(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $before = $this->snapshot();
        $this->assertSame(5, $before['users']);
        $this->assertSame(2, $before['projects']);

        $this->migration()->up();

        $after = $this->snapshot();

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                $after[$table],
                "Upgrade changed the row count of {$table} ({$count} -> {$after[$table]}).",
            );
        }
    }

    public function test_upgrade_assigns_every_project_to_a_real_workspace(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();

        $this->assertSame(0, DB::table('projects')->whereNull('workspace_id')->count());
        $this->assertSame(2, DB::table('projects')->whereNotNull('workspace_id')->count());

        $workspaceIds = DB::table('projects')->pluck('workspace_id')->unique();
        $this->assertCount(1, $workspaceIds, 'Legacy projects should share one default workspace.');

        $workspace = DB::table('workspaces')->where('id', $workspaceIds->first())->first();
        $this->assertNotNull($workspace);
        $this->assertTrue((bool) $workspace->is_default);
        $this->assertSame('default-workspace', $workspace->slug);
    }

    public function test_upgrade_does_not_lock_existing_operators_out(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();

        $workspaceId = DB::table('projects')->where('slug', 'wasla')->value('workspace_id');

        // The legacy platform owner keeps full reach.
        $owner = User::query()->where('email', 'owner@legacy.test')->firstOrFail();
        $this->assertTrue(Access::for($owner)->isPlatformOwner());
        $this->assertTrue(Access::for($owner)->allows(Capability::SETTINGS_MANAGE));

        // Legacy admin/developer/observer become workspace members with the
        // equivalent role, so their day-to-day access is unchanged.
        foreach ([
            'admin@legacy.test' => 'workspace_owner',
            'dev@legacy.test' => 'workspace_admin',
            'obs@legacy.test' => 'workspace_member',
        ] as $email => $expectedRole) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $membership = DB::table('workspace_members')
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $user->id)
                ->first();

            $this->assertNotNull($membership, "{$email} lost their access in the upgrade.");
            $this->assertSame($expectedRole, $membership->role, "{$email} got the wrong role.");
            $this->assertSame('active', $membership->status);
        }

        // A developer keeps exactly the read access they had before.
        $dev = User::query()->where('email', 'dev@legacy.test')->firstOrFail();
        $wasla = Project::query()->where('slug', 'wasla')->firstOrFail();
        $this->assertTrue(Access::for($dev)->allows(Capability::PROJECTS_VIEW, 'project', null, $wasla));
        $this->assertTrue(Access::for($dev)->allows(Capability::DATA_VIEW, 'project', null, $wasla));

        // An observer is still read-only.
        $obs = User::query()->where('email', 'obs@legacy.test')->firstOrFail();
        $this->assertTrue(Access::for($obs)->allows(Capability::DATA_VIEW, 'project', null, $wasla));
        $this->assertFalse(Access::for($obs)->allows(Capability::DATA_WRITE, 'project', null, $wasla));
    }

    public function test_upgrade_grants_nothing_to_an_unassigned_legacy_user(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();

        $none = User::query()->where('email', 'none@legacy.test')->firstOrFail();
        $access = Access::for($none);

        // 0.3.0 denied this user the panel (NULL cp_role); 0.4.0 must too.
        $this->assertSame([], $access->accessibleWorkspaceIds());
        $this->assertSame([], $access->accessibleProjectIds());
        $this->assertFalse($none->canAccessPanel(app(Panel::class)));
    }

    public function test_upgrade_is_idempotent(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();
        $first = $this->snapshot();
        $workspaceCount = DB::table('workspaces')->count();
        $memberCount = DB::table('workspace_members')->count();

        // Running the migration body again must not duplicate anything.
        $this->migration()->up();

        $this->assertSame($first, $this->snapshot());
        $this->assertSame($workspaceCount, DB::table('workspaces')->count());
        $this->assertSame($memberCount, DB::table('workspace_members')->count());
    }

    public function test_upgrade_on_an_empty_installation_creates_no_junk(): void
    {
        $this->rewindToV030();
        DB::table('users')->delete();
        DB::table('projects')->delete();

        $this->migration()->up();

        // No projects existed, so no default workspace should be invented.
        $this->assertSame(0, DB::table('workspaces')->count());
        $this->assertSame(0, DB::table('workspace_members')->count());
        $this->assertSame(0, DB::table('projects')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_upgrade_preserves_legacy_cp_role_column_for_rollback(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();

        // The legacy column is untouched, so a rollback to 0.3.0 still works.
        $this->assertSame('admin', DB::table('users')->where('email', 'admin@legacy.test')->value('cp_role'));
        $this->assertSame(1, (int) DB::table('users')->where('email', 'owner@legacy.test')->value('is_admin'));
    }

    /**
     * The upgrade must make the legacy owner's platform role EXPLICIT.
     *
     * The 0.4.0 navigation filters its groups by capability, so an installation
     * whose owner is identified only by `is_admin` would render an empty
     * Security group for that owner. The backfill writes the role down.
     */
    public function test_upgrade_backfills_an_explicit_platform_role_for_the_legacy_owner(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $this->migration()->up();

        $this->assertSame(
            'platform_owner',
            DB::table('users')->where('email', 'owner@legacy.test')->value('platform_role'),
        );

        // Non-owners must NOT be promoted.
        foreach (['admin@legacy.test', 'dev@legacy.test', 'obs@legacy.test', 'none@legacy.test'] as $email) {
            $this->assertNull(
                DB::table('users')->where('email', $email)->value('platform_role'),
                "{$email} was wrongly given a platform role.",
            );
        }
    }

    public function test_upgrade_never_overwrites_an_explicitly_assigned_platform_role(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        // First pass moves the installation to 0.4.0 and backfills.
        $this->migration()->up();
        $this->assertSame(
            'platform_owner',
            DB::table('users')->where('email', 'owner@legacy.test')->value('platform_role'),
        );

        // An operator then deliberately demotes the account…
        DB::table('users')->where('email', 'owner@legacy.test')->update([
            'platform_role' => 'platform_admin',
        ]);

        // …and re-running the migration must not undo that decision.
        $this->migration()->up();

        $this->assertSame(
            'platform_admin',
            DB::table('users')->where('email', 'owner@legacy.test')->value('platform_role'),
        );
    }

    public function test_project_ids_and_settings_are_unchanged_by_the_upgrade(): void
    {
        $this->rewindToV030();
        $this->seedV030Data();

        $before = DB::table('projects')->orderBy('id')->get(['id', 'name', 'slug', 'db_name', 'health_status'])->toArray();

        $this->migration()->up();

        $after = DB::table('projects')->orderBy('id')->get(['id', 'name', 'slug', 'db_name', 'health_status'])->toArray();

        $this->assertEquals($before, $after, 'Project identity or settings changed during the upgrade.');
    }
}
