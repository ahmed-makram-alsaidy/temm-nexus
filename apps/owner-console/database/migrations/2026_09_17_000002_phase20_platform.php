<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 platform tables (owner console DB only — never project DBs).
 * Secrets/keys are stored encrypted or hashed; values are never displayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_sql_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->text('sql');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['project_id', 'name']);
        });

        Schema::create('sql_query_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('query_hash', 64);
            $t->string('category', 20); // read | write | explain | blocked
            $t->string('status', 20);   // ok | error | blocked | timeout
            $t->integer('duration_ms')->default(0);
            $t->integer('rows')->default(0);
            $t->text('redacted_sql');
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('project_functions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('slug', 120);
            $t->text('description')->nullable();
            $t->string('type', 30); // static | db_lookup | transform
            $t->boolean('enabled')->default(true);
            $t->json('methods')->nullable(); // e.g. ["GET","POST"]
            $t->string('auth_mode', 30)->default('key'); // public | key | user | internal
            $t->integer('timeout_s')->default(10);
            $t->integer('rate_limit_per_min')->default(60);
            $t->foreignId('current_version_id')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'slug']);
        });

        Schema::create('function_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('function_id')->constrained('project_functions')->cascadeOnDelete();
            $t->integer('version');
            $t->json('config'); // executor config (no secrets — references by name)
            $t->foreignId('deployed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['function_id', 'version']);
        });

        Schema::create('function_invocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('function_id')->constrained('project_functions')->cascadeOnDelete();
            $t->integer('version');
            $t->string('request_id', 64);
            $t->string('actor', 160)->default('owner');
            $t->integer('status')->default(200);
            $t->integer('duration_ms')->default(0);
            $t->json('request')->nullable();  // redacted + capped
            $t->json('response')->nullable(); // redacted + capped
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['function_id', 'created_at']);
        });

        Schema::create('project_api_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('prefix', 16);
            $t->string('key_hash', 128);
            $t->json('scopes');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['prefix']);
        });

        Schema::create('project_secrets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->text('value'); // encrypted at rest (Laravel Crypt); never displayed
            $t->text('description')->nullable();
            $t->string('env', 30)->default('local');
            $t->timestamp('last_rotated_at')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'name']);
        });

        Schema::create('project_webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('url', 2048);
            $t->json('events');
            $t->boolean('enabled')->default(true);
            $t->text('signing_secret'); // encrypted at rest
            $t->integer('max_attempts')->default(5);
            $t->integer('timeout_s')->default(10);
            $t->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_id')->constrained('project_webhooks')->cascadeOnDelete();
            $t->string('request_id', 64);
            $t->string('event', 120);
            $t->string('status', 20)->default('pending'); // pending|delivered|failed|exhausted
            $t->integer('http_code')->nullable();
            $t->integer('duration_ms')->default(0);
            $t->integer('attempts')->default(0);
            $t->json('payload')->nullable(); // redacted + capped
            $t->text('response')->nullable(); // capped
            $t->timestamp('next_retry_at')->nullable();
            $t->timestamps();
            $t->index(['webhook_id', 'created_at']);
        });

        Schema::create('project_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('cron', 60);
            $t->string('target_type', 30); // artisan | function | webhook
            $t->string('target_ref', 255);
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_run_at')->nullable();
            $t->timestamp('next_run_at')->nullable();
            $t->string('last_status', 20)->nullable();
            $t->integer('last_duration_ms')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'name']);
        });

        Schema::create('task_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained('project_tasks')->cascadeOnDelete();
            $t->string('request_id', 64);
            $t->string('status', 20); // ok | error
            $t->integer('duration_ms')->default(0);
            $t->text('output')->nullable(); // capped + sanitized
            $t->text('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['task_id', 'created_at']);
        });

        Schema::create('project_auth_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $t->json('providers')->nullable();
            $t->json('password_policy')->nullable();
            $t->json('session_policy')->nullable();
            $t->json('email_templates')->nullable();
            $t->timestamps();
        });

        Schema::create('project_schema_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('kind', 40); // table_created | column_added | fk_created | ...
            $t->json('detail');
            $t->text('sql')->nullable();
            $t->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'created_at']);
        });

        Schema::create('realtime_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('channel', 160);
            $t->string('event', 160);
            $t->json('payload')->nullable(); // capped
            $t->string('request_id', 64)->nullable();
            $t->boolean('verified')->default(false); // loop-back receipt proof
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'created_at']);
        });

        Schema::create('api_route_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $t->json('routes');
            $t->timestamp('captured_at')->nullable();
            $t->timestamps();
        });

        Schema::create('storage_bucket_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('bucket', 120);
            $t->string('visibility', 20)->default('private');
            $t->integer('max_size_mb')->default(25);
            $t->json('allowed_mimes')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'bucket']);
        });

        Schema::create('control_plane_roles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->json('permissions');
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('cp_role', 30)->default('owner');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('cp_role');
        });
        foreach ([
            'control_plane_roles', 'storage_bucket_settings', 'api_route_snapshots',
            'realtime_events', 'project_schema_changes', 'project_auth_configs',
            'task_runs', 'project_tasks', 'webhook_deliveries', 'project_webhooks',
            'project_secrets', 'project_api_keys', 'function_invocations',
            'function_versions', 'project_functions', 'sql_query_history',
            'saved_sql_queries',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
