<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21: ERD layout persistence + multi-node infrastructure model.
 *
 * Owner-console DB only — never project DBs. All migration operations are
 * SQLite-compatible (phpunit runs RefreshDatabase on sqlite :memory:).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('infrastructure_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();           // e.g. node-local-01, db-01
            $t->string('hostname', 255)->nullable();    // IP/host reference, never a secret
            $t->string('environment', 30)->default('local');
            $t->json('roles')->nullable();              // proxy|application|database|redis|queue_worker|realtime|monitoring|backup
            $t->string('status', 16)->default('unknown'); // healthy|degraded|offline|unknown
            $t->string('provider', 60)->nullable();
            $t->string('region', 60)->nullable();
            $t->integer('cpu_cores')->nullable();
            $t->integer('ram_mb')->nullable();
            $t->integer('disk_gb')->nullable();
            $t->float('cpu_pct')->nullable();
            $t->float('ram_pct')->nullable();
            $t->float('disk_pct')->nullable();
            $t->string('agent_version', 30)->nullable();
            $t->boolean('enabled')->default(true);
            $t->string('token_prefix', 16)->nullable()->index();
            $t->string('token_hash', 128)->nullable();  // sha256 hex, never plaintext
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('infrastructure_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('node_id')->constrained('infrastructure_nodes')->cascadeOnDelete();
            $t->string('key', 40);                      // postgres|redis|caddy|laravel-api|horizon|reverb|backup-worker
            $t->string('label', 120)->nullable();
            $t->string('scope', 16)->default('global'); // global|project
            $t->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $t->string('status', 16)->default('unknown');
            $t->string('endpoint', 255)->nullable();    // host:port reference, no credentials
            $t->string('health', 16)->nullable();
            $t->string('version', 60)->nullable();
            $t->timestamp('last_check_at')->nullable();
            $t->json('config')->nullable();             // non-secret config only
            $t->timestamps();
            $t->unique(['node_id', 'key', 'project_id']);
        });

        Schema::create('project_service_nodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('service', 40);                  // app|db|redis|worker|realtime|storage
            $t->foreignId('node_id')->nullable()->constrained('infrastructure_nodes')->nullOnDelete();
            $t->string('endpoint_override', 255)->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'service']);
        });

        Schema::create('erd_layouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->string('schema', 63)->default('public');
            $t->json('layout');                         // {table: {x, y, collapsed}} (+ version key)
            $t->timestamps();
            $t->unique(['project_id', 'user_id', 'schema']);
        });

        Schema::table('projects', function (Blueprint $t) {
            // Optional per-project endpoint overrides (Phase 21B config abstraction).
            // NULL = fall back to PROJECT_DB_HOST / REDIS_HOST / REVERB_* env.
            $t->string('db_host', 255)->nullable();
            $t->integer('db_port')->nullable();
            $t->string('redis_host', 255)->nullable();
            $t->integer('redis_port')->nullable();
            $t->string('reverb_host', 255)->nullable();
            $t->integer('reverb_port')->nullable();
            $t->string('infra_profile', 20)->nullable(); // single|split|distributed
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $t) {
            $t->dropColumn([
                'db_host', 'db_port', 'redis_host', 'redis_port',
                'reverb_host', 'reverb_port', 'infra_profile',
            ]);
        });
        Schema::dropIfExists('erd_layouts');
        Schema::dropIfExists('project_service_nodes');
        Schema::dropIfExists('infrastructure_services');
        Schema::dropIfExists('infrastructure_nodes');
    }
};
