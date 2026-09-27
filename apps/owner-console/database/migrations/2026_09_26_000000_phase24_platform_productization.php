<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 — Platform Productization.
 *
 * One coherent lifecycle: PROJECT → ENVIRONMENTS → CONNECTIONS → ANALYSIS →
 * MIGRATION PLAN → MIGRATION RUN → VALIDATION → CLIENT CONNECTION →
 * DEPLOYMENT → BACKUP → OBSERVABILITY → READINESS.
 *
 * All tables are project-scoped. Secrets are never stored in plaintext:
 * migration sources reference vault secret names, never values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 24C Environments ────────────────────────────────────────────────
        Schema::create('project_environments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->string('type');                 // development|staging|production
            $t->string('status')->default('active'); // active|inactive|archived
            $t->string('api_base_url')->nullable();
            $t->json('database_connection')->nullable(); // host/port/database/user — password via vault ref
            $t->string('database_secret_ref')->nullable(); // vault secret name holding the password
            $t->string('redis_namespace')->nullable();
            $t->string('storage_namespace')->nullable();
            $t->string('realtime_namespace')->nullable();
            $t->unsignedBigInteger('node_id')->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('disposable')->default(false); // may be reset by drills/clean runs
            $t->json('config')->nullable();
            $t->timestamps();

            $t->unique(['project_id', 'slug']);
            $t->index(['project_id', 'type']);
        });

        // ── 24A Migration Center ────────────────────────────────────────────
        Schema::create('migration_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('type')->default('supabase'); // source adapter id
            $t->string('display_name');
            $t->string('source_ref')->nullable();   // project ref / host (no credentials)
            $t->json('connection')->nullable();     // host/port/database/schema — NO passwords
            $t->json('secret_refs')->nullable();    // vault secret names: {password: 'NAME', ...}
            $t->boolean('read_only')->default(true);
            $t->string('status')->default('pending'); // pending|ready|error|disabled
            $t->string('last_error')->nullable();
            $t->timestamp('last_analyzed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });

        Schema::create('migration_analyses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('migration_source_id')->constrained('migration_sources')->cascadeOnDelete();
            $t->string('run_id');                   // uuid per analyze invocation
            $t->string('status')->default('running'); // running|completed|failed
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->json('counts')->nullable();         // per-object-kind counts
            $t->json('warnings')->nullable();
            $t->json('errors')->nullable();
            $t->string('source_fingerprint', 64)->nullable(); // deterministic source schema fingerprint
            $t->string('driver_version')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'migration_source_id']);
        });

        Schema::create('migration_analysis_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('migration_analysis_id')->constrained('migration_analyses')->cascadeOnDelete();
            $t->string('kind');       // table|view|matview|function|trigger|policy|enum|extension|auth|storage|realtime|cron|edge_function|client
            $t->string('schema_name')->nullable();
            $t->string('name');
            $t->json('attributes')->nullable();     // kind-specific inventory payload
            $t->string('compatibility')->nullable(); // classification from 24A.5
            $t->json('risks')->nullable();           // risk codes from 24A.6
            $t->timestamps();

            $t->index(['migration_analysis_id', 'kind']);
        });

        Schema::create('migration_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('migration_analysis_id')->constrained('migration_analyses')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('name');
            $t->string('status')->default('draft'); // draft|approved|retired
            $t->json('strategy')->nullable();       // plan-level strategy options
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('migration_plan_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('migration_plan_id')->constrained('migration_plans')->cascadeOnDelete();
            $t->string('source_kind');
            $t->string('source_schema')->nullable();
            $t->string('source_name');
            $t->string('target_kind')->nullable();
            $t->string('target_name')->nullable();
            $t->string('strategy')->default('direct_copy'); // direct_copy|transform|rebuild|archive|skip|...
            $t->string('transform')->nullable();     // transform pipeline spec (json string or name)
            $t->unsignedInteger('stage')->default(0);      // dependency-ordered stage
            $t->string('validation')->nullable();    // validator name(s)
            $t->string('status')->default('DISCOVERED'); // DISCOVERED|MAPPED|READY|MIGRATED|VALIDATED|NEEDS_REVIEW|BLOCKED|SKIPPED_WITH_REASON
            $t->text('skip_reason')->nullable();
            $t->unsignedInteger('order_override')->nullable();
            $t->json('meta')->nullable();            // column maps, key strategy, dependencies
            $t->timestamps();

            $t->index(['migration_plan_id', 'stage']);
            $t->index(['migration_plan_id', 'status']);
        });

        Schema::create('migration_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('migration_plan_id')->constrained('migration_plans')->cascadeOnDelete();
            $t->string('run_id');
            $t->boolean('dry_run')->default(false);
            $t->string('mode');                      // dry_run|rehearsal|real
            $t->string('target_connection')->nullable(); // redacted DSN of target
            $t->boolean('target_disposable')->default(false);
            $t->string('status')->default('pending'); // pending|running|completed|failed|cancelled
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->json('progress')->nullable();        // {total, done, failed, skipped}
            $t->json('failure')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });

        Schema::create('migration_run_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $t->foreignId('migration_plan_item_id')->constrained('migration_plan_items')->cascadeOnDelete();
            $t->unsignedInteger('stage');
            $t->string('status')->default('pending'); // pending|running|completed|failed|skipped
            $t->unsignedBigInteger('rows_written')->default(0);
            $t->json('validation')->nullable();      // validator results per item
            $t->text('error')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['migration_run_id', 'status']);
        });

        Schema::create('migration_artifacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('migration_run_id')->nullable()->constrained('migration_runs')->nullOnDelete();
            $t->foreignId('migration_analysis_id')->nullable()->constrained('migration_analyses')->nullOnDelete();
            $t->string('kind');                      // analysis|report|manifest|validation|mapping
            $t->string('path');                      // storage path, gitignored, non-public
            $t->json('summary')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'kind']);
        });

        // ── 24D Backup Center ───────────────────────────────────────────────
        Schema::create('backup_destinations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name');
            $t->string('driver')->default('local'); // local|s3-compatible
            $t->json('config')->nullable();         // endpoint/bucket/prefix — credentials via vault ref
            $t->string('secret_ref')->nullable();   // vault secret name for access keys
            $t->string('status')->default('active'); // active|disabled
            $t->string('last_error')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'driver']);
        });

        Schema::create('backup_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->foreignId('destination_id')->nullable()->constrained('backup_destinations')->nullOnDelete();
            $t->string('name');
            $t->string('scope')->default('database'); // database|storage
            $t->string('schedule')->default('daily'); // cron 5-field or preset
            $t->unsignedInteger('retention_days')->default(14);
            $t->boolean('encrypted')->default(false);
            $t->string('status')->default('active'); // active|paused
            $t->timestamp('last_run_at')->nullable();
            $t->timestamp('last_restore_test_at')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });

        Schema::create('backup_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('backup_policy_id')->nullable()->constrained('backup_policies')->nullOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('type')->default('database');   // database|storage|restore_drill
            $t->string('trigger')->default('manual');  // manual|schedule|drill
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('checksum', 128)->nullable();
            $t->string('destination')->nullable();
            $t->string('status')->default('running');  // running|completed|failed|drill_running|drill_passed|drill_failed
            $t->text('error')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'type', 'status']);
        });

        // ── 24E Schema Diff / Drift ─────────────────────────────────────────
        Schema::create('schema_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('label')->nullable();
            $t->string('source');                     // live|migration_files
            $t->string('fingerprint', 64);            // deterministic schema fingerprint
            $t->json('snapshot');                     // normalized schema shape
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'environment_id']);
        });

        // ── 24F Secrets Vault extensions (ProjectSecret exists since 20M) ───
        Schema::table('project_secrets', function (Blueprint $t) {
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('category')->default('application'); // database|redis|storage|oauth|webhook|whatsapp|ecommerce|payment|partner|application|custom
            $t->unsignedInteger('version')->default(1);
            $t->string('status')->default('active');  // active|retired|revoked
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->json('rotation_meta')->nullable();    // {old_version, current_version, next_name}
        });

        // ── 24G Resource & Cost Observability ───────────────────────────────
        Schema::create('resource_metric_samples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('metric');                     // db_size_bytes|db_connections|redis_memory_bytes|queue_depth|failed_jobs|storage_bytes|backup_bytes|requests_24h|errors_5xx_24h|node_cpu_percent|node_ram_bytes
            $t->double('value');
            $t->string('unit')->default('bytes');
            $t->string('attribution')->default('project'); // project|node — honesty label
            $t->timestamp('sampled_at');
            $t->timestamps();

            $t->index(['project_id', 'metric', 'sampled_at']);
        });

        Schema::create('resource_thresholds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('metric');
            $t->double('warning_value');
            $t->double('critical_value')->nullable();
            $t->timestamps();

            $t->unique(['project_id', 'metric']);
        });

        Schema::create('cost_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name');                       // e.g. shared VPS
            $t->double('monthly_cost');               // operator-entered
            $t->string('currency', 8)->default('EGP');
            $t->string('allocation')->default('equal'); // equal|manual|resource_weighted
            $t->double('allocated_cost')->nullable(); // computed ESTIMATE
            $t->unsignedInteger('month');             // 202609
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['project_id', 'month']);
        });

        // ── 24H Production Readiness ────────────────────────────────────────
        Schema::create('readiness_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->string('category');                   // Database|Auth|Storage|...|Observability
            $t->string('check_key');
            $t->string('title');
            $t->string('origin')->default('machine'); // machine|manual
            $t->string('status');                     // green|yellow|red|not_applicable
            $t->text('detail')->nullable();
            $t->text('evidence')->nullable();
            $t->boolean('blocks_production')->default(false);
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('acknowledgement_note')->nullable();
            $t->timestamp('evaluated_at')->nullable();
            $t->timestamps();

            $t->unique(['project_id', 'environment_id', 'check_key']);
        });

        Schema::create('readiness_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('environment_id')->nullable()->references('id')->on('project_environments')->nullOnDelete();
            $t->json('summary');                      // counts per status + blockers list
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'environment_id']);
        });

        // ── 24B Onboarding wizard state ─────────────────────────────────────
        Schema::create('onboarding_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('flow')->default('create');   // create|import
            $t->string('status')->default('in_progress'); // in_progress|completed|abandoned
            $t->unsignedInteger('current_step')->default(0);
            $t->json('state')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sessions');
        Schema::dropIfExists('readiness_snapshots');
        Schema::dropIfExists('readiness_checks');
        Schema::dropIfExists('cost_entries');
        Schema::dropIfExists('resource_thresholds');
        Schema::dropIfExists('resource_metric_samples');
        Schema::table('project_secrets', function (Blueprint $t) {
            $t->dropForeign(['environment_id']);
            $t->dropColumn(['environment_id', 'category', 'version', 'status', 'created_by', 'updated_by', 'rotation_meta']);
        });
        Schema::dropIfExists('schema_snapshots');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_policies');
        Schema::dropIfExists('backup_destinations');
        Schema::dropIfExists('migration_artifacts');
        Schema::dropIfExists('migration_run_items');
        Schema::dropIfExists('migration_runs');
        Schema::dropIfExists('migration_plan_items');
        Schema::dropIfExists('migration_plans');
        Schema::dropIfExists('migration_analysis_items');
        Schema::dropIfExists('migration_analyses');
        Schema::dropIfExists('migration_sources');
        Schema::dropIfExists('project_environments');
    }
};
