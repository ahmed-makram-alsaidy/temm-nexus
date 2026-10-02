<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 0.4.0 Phase B — Workspace / Client layer.
 *
 * Introduces the three-scope model the product is organised around:
 *
 *     PLATFORM  └─  WORKSPACE (client / company / team)  └─  PROJECT
 *
 * SAFETY / BACKWARD COMPATIBILITY
 * -------------------------------
 *  - `projects.workspace_id` is NULLABLE and has NO foreign-key constraint, so
 *    an existing project can never be broken or orphaned by this migration.
 *    The application treats NULL as "unassigned (legacy)" and surfaces it as
 *    "Ungrouped" — never as an error.
 *  - Every existing project is backfilled into a real workspace so the UI has
 *    a sensible home for it, but the backfill is idempotent and additive: it
 *    creates rows, it never rewrites project data.
 *  - No existing column is dropped, renamed, or narrowed. No existing row is
 *    deleted. Migration history, CDC checkpoints, backups, and audit rows are
 *    untouched — they hang off `projects.id`, which does not change.
 *  - `users.cp_role` is preserved. `users.platform_role` is added and LEFT
 *    NULL; the legacy role is translated at read time by `Access`, so an
 *    upgraded installation behaves identically until an operator assigns new
 *    roles deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Workspaces ─────────────────────────────────────────────────
        if (! Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('kind', 32)->default('client'); // client|company|team|internal
                $table->string('status', 20)->default('active'); // active|paused|archived
                $table->text('description')->nullable();
                $table->string('primary_contact_name')->nullable();
                $table->string('primary_contact_email')->nullable();
                $table->string('timezone')->default('UTC');
                $table->string('locale', 12)->default('en');
                $table->string('color', 16)->nullable();  // identity accent (UI only)
                // Operational summary, refreshed on demand — never probed per request.
                $table->string('health_status', 20)->default('unknown'); // healthy|degraded|unhealthy|unknown
                $table->timestamp('health_checked_at')->nullable();
                $table->json('settings')->nullable();
                // Marks the auto-created home for pre-0.4.0 projects.
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->index('status');
                $table->index('kind');
            });
        }

        // ── Workspace membership ───────────────────────────────────────
        if (! Schema::hasTable('workspace_members')) {
            Schema::create('workspace_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 40); // Roles::workspaceRoles()
                $table->string('status', 20)->default('active'); // active|invited|suspended
                $table->unsignedBigInteger('invited_by')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }

        // ── Project membership (narrower than, and additive to, workspace) ──
        if (! Schema::hasTable('project_members')) {
            Schema::create('project_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('role', 40); // Roles::projectRoles()
                $table->string('status', 20)->default('active');
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamps();

                $table->unique(['project_id', 'user_id']);
                $table->index(['user_id', 'status']);
            });
        }

        // ── Project -> workspace pointer ───────────────────────────────
        if (! Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $table) {
                // No FK on purpose: a legacy project must never fail to save.
                $table->unsignedBigInteger('workspace_id')->nullable()->after('id');
                $table->index('workspace_id');
            });
        }

        // ── Platform role on users (additive, nullable) ────────────────
        if (! Schema::hasColumn('users', 'platform_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('platform_role', 40)->nullable()->after('cp_role');
                $table->string('job_title')->nullable()->after('platform_role');
            });
        }

        // ── Per-user saved UI preferences (Inspect Mode / layout) ──────
        if (! Schema::hasTable('user_ui_preferences')) {
            Schema::create('user_ui_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // NULL scope = platform-wide preference.
                $table->unsignedBigInteger('workspace_id')->nullable();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('key', 120);
                $table->json('value')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'workspace_id', 'project_id', 'key'], 'user_ui_pref_unique');
                $table->index(['user_id', 'key']);
            });
        }

        $this->backfillExistingProjects();
    }

    /**
     * Give every pre-0.4.0 project a home without changing its identity, and
     * translate legacy global roles into the new scoped model.
     *
     * Idempotent: re-running creates nothing new. Safe on an empty database.
     */
    private function backfillExistingProjects(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('projects', 'workspace_id')) {
            return;
        }

        $this->backfillPlatformRoles();

        $unassigned = DB::table('projects')->whereNull('workspace_id')->count();
        if ($unassigned === 0) {
            return;
        }

        $existingDefaultId = DB::table('workspaces')->where('is_default', true)->value('id');

        $workspaceId = $existingDefaultId ?: DB::table('workspaces')->insertGetId([
            'name' => 'Default Workspace',
            'slug' => $this->uniqueSlug('default-workspace'),
            'kind' => 'internal',
            'status' => 'active',
            'description' => 'Home for projects that existed before workspaces were introduced in 0.4.0. Rename or split this workspace at any time.',
            'timezone' => 'UTC',
            'locale' => 'en',
            'health_status' => 'unknown',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('projects')->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);

        // Translate existing global roles into memberships so nobody loses
        // access they had before the upgrade. `owner` is handled by the
        // platform role instead (owners already bypass every check).
        $legacyMembers = DB::table('users')
            ->whereIn('cp_role', ['admin', 'developer', 'observer'])
            ->where(function ($q) {
                $q->where('is_admin', false)->orWhereNull('is_admin');
            })
            ->pluck('id');

        foreach ($legacyMembers as $userId) {
            $role = DB::table('users')->where('id', $userId)->value('cp_role');
            $workspaceRole = match ($role) {
                'admin' => 'workspace_owner',
                'developer' => 'workspace_admin',
                default => 'workspace_member',
            };

            $exists = DB::table('workspace_members')
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)
                ->exists();

            if (! $exists) {
                DB::table('workspace_members')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'role' => $workspaceRole,
                    'status' => 'active',
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'workspace';
        $candidate = $slug;
        $i = 2;

        while (DB::table('workspaces')->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$i++;
        }

        return $candidate;
    }

    /**
     * Translate the legacy global-owner flag into an explicit platform role.
     *
     * WHY THIS IS REQUIRED
     * The 0.4.0 navigation filters its groups by CAPABILITY. A legacy
     * installation's owner is identified only by `is_admin = 1` / `cp_role =
     * 'owner'`, which `Access` bridges to Platform Owner at read time — but a
     * capability-filtered navigation group that asks
     * `platform_role === platform_owner` would render nothing, and the Security
     * group would silently vanish for the very person who owns the platform.
     *
     * The backfill makes the role EXPLICIT and auditable rather than leaving it
     * implied. It only ever fills a NULL, so a role an operator has already
     * assigned deliberately is never overwritten.
     */
    private function backfillPlatformRoles(): void
    {
        if (! Schema::hasColumn('users', 'platform_role')) {
            return;
        }

        // Legacy platform owners: the admin flag, or the owner role.
        DB::table('users')
            ->whereNull('platform_role')
            ->where(function ($q) {
                $q->where('is_admin', true)->orWhere('cp_role', 'owner');
            })
            ->update(['platform_role' => 'platform_owner']);
    }

    /**
     * Down is deliberately additive-safe: the pointer column and membership
     * tables are dropped only because rolling back to 0.3.0 means the concept
     * no longer exists. Project rows themselves are never touched.
     */
    public function down(): void
    {
        if (Schema::hasColumn('projects', 'workspace_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropIndex(['workspace_id']);
                $table->dropColumn('workspace_id');
            });
        }

        if (Schema::hasColumn('users', 'platform_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['platform_role', 'job_title']);
            });
        }

        Schema::dropIfExists('user_ui_preferences');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
