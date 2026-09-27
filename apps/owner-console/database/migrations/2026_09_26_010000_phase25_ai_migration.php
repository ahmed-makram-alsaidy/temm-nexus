<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25 — Supabase Connector + AI Migration Copilot + Client Repository Linking.
 *
 * Credentials (PAT, DB passwords, AI API keys, Git tokens) are NEVER stored
 * here — only vault secret references. All AI output goes through
 * PLAN → PATCH → REVIEW → APPROVE → APPLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 25A Supabase account connector ─────────────────────────────────
        Schema::create('external_account_connections', function (Blueprint $t) {
            $t->id();
            $t->string('provider')->default('supabase');
            $t->string('display_name');
            $t->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $t->text('secret_encrypted')->nullable();       // PAT encrypted at rest (APP_KEY — same crypto as the vault)
            $t->string('secret_ref')->nullable();           // optional vault delegation
            $t->string('status')->default('unverified');    // unverified|connected|error
            $t->string('last_result')->nullable();          // PASS|INVALID_TOKEN|...
            $t->timestamp('last_verified_at')->nullable();
            $t->json('metadata')->nullable();               // account label etc. (never secrets)
            $t->timestamps();

            $t->index(['provider', 'owner_user_id']);
        });

        // ── 25B source profile (management metadata on the Phase 24 source) ─
        Schema::table('migration_sources', function (Blueprint $t) {
            $t->foreignId('external_account_connection_id')->nullable()
                ->constrained('external_account_connections')->nullOnDelete();
            $t->string('management_project_ref')->nullable();  // supabase project ref
            $t->string('region')->nullable();
            $t->string('organization')->nullable();
            $t->json('capabilities')->nullable();              // capability matrix snapshot
        });

        // ── 25D Client repository linking ──────────────────────────────────
        Schema::create('client_repositories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('display_name');
            $t->string('source_type')->default('local');    // local|git
            $t->string('root_path')->nullable();            // approved local root (persisted)
            $t->string('git_url')->nullable();              // metadata-only for git
            $t->string('git_branch')->nullable();
            $t->string('credential_ref')->nullable();       // vault ref (git tokens) — metadata model only
            $t->string('framework')->nullable();            // flutter|javascript|react|php|unknown
            $t->string('status')->default('linked');        // linked|scanned|error
            $t->json('inventory')->nullable();              // framework/package/env-file inventory
            $t->timestamp('last_scanned_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });

        // ── 25E client callsite manifest ───────────────────────────────────
        Schema::create('client_callsites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_repository_id')->constrained('client_repositories')->cascadeOnDelete();
            $t->string('file');
            $t->unsignedInteger('line')->nullable();
            $t->string('category');                         // auth|database|rpc|functions|storage|realtime|url|client_init|secret
            $t->string('target')->nullable();               // table/function/bucket/channel
            $t->string('language')->nullable();             // dart|javascript|typescript|php
            $t->string('confidence')->default('medium');    // high|medium|low
            $t->string('status')->default('DISCOVERED');    // DISCOVERED|MAPPED|CONVERTED|REVIEW|IGNORED_WITH_REASON
            $t->text('evidence_hash')->nullable();          // hashed evidence (never raw secrets)
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['client_repository_id', 'category']);
            $t->index(['client_repository_id', 'status']);
        });

        // ── 25F AI provider gateway ────────────────────────────────────────
        Schema::create('ai_provider_configs', function (Blueprint $t) {
            $t->id();
            $t->string('provider');                          // openai|gemini|anthropic|openrouter|openai_compatible|fake
            $t->string('display_name');
            $t->string('base_url')->nullable();
            $t->string('model')->nullable();
            $t->text('secret_encrypted')->nullable();    // API key encrypted at rest (APP_KEY)
            $t->string('secret_ref')->nullable();        // optional vault delegation (project-scoped)
            $t->boolean('enabled')->default(true);
            $t->unsignedInteger('timeout_seconds')->default(60);
            $t->unsignedInteger('max_output_tokens')->default(4096);
            $t->json('pricing')->nullable();                 // operator-configured {input_per_1k, output_per_1k, currency}
            $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete(); // null = global
            $t->string('status')->default('unverified');
            $t->timestamps();
        });

        Schema::create('ai_model_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_provider_config_id')->constrained('ai_provider_configs')->cascadeOnDelete();
            $t->string('name');                              // planner|builder|validator|custom
            $t->string('model')->nullable();                 // overrides provider default
            $t->unsignedInteger('max_output_tokens')->nullable();
            $t->timestamps();

            $t->unique(['ai_provider_config_id', 'name']);
        });

        Schema::create('ai_usage_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_provider_config_id')->nullable()->constrained('ai_provider_configs')->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->string('profile')->nullable();
            $t->unsignedBigInteger('input_tokens')->default(0);
            $t->unsignedBigInteger('output_tokens')->default(0);
            $t->decimal('estimated_cost', 12, 4)->nullable(); // only when operator pricing configured
            $t->string('currency', 8)->nullable();
            $t->timestamps();

            $t->index(['project_id', 'created_at']);
        });

        // ── 25G/H copilot runs (history) ───────────────────────────────────
        Schema::create('copilot_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('migration_analysis_id')->nullable()->constrained('migration_analyses')->nullOnDelete();
            $t->foreignId('client_repository_id')->nullable()->constrained('client_repositories')->nullOnDelete();
            $t->string('run_id');
            $t->string('mode');                              // advisor|builder|validator
            $t->string('action');                            // explain_blockers|analyze_rls|... (action cards)
            $t->string('provider')->nullable();
            $t->string('model')->nullable();
            $t->string('status')->default('completed');      // running|completed|failed|rejected
            $t->json('input_refs')->nullable();              // artifact references (ids) — no raw dumps
            $t->json('result')->nullable();                  // structured AI output
            $t->json('tool_calls')->nullable();              // executed tool ledger
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'mode']);
        });

        // ── 25I patch workspace ────────────────────────────────────────────
        Schema::create('ai_patch_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('copilot_run_id')->nullable()->constrained('copilot_runs')->nullOnDelete();
            $t->foreignId('client_repository_id')->nullable()->constrained('client_repositories')->nullOnDelete();
            $t->string('run_id');
            $t->string('workspace_path');                    // private workspace dir
            $t->string('target_root');                       // approved root patches may apply to
            $t->string('status')->default('proposed');       // proposed|approved|rejected|applied|applied_partial|failed
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->json('git_status')->nullable();              // before/after where git exists
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });

        Schema::create('ai_patch_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_patch_run_id')->constrained('ai_patch_runs')->cascadeOnDelete();
            $t->string('path');                              // relative to target root
            $t->string('action');                            // create|modify
            $t->text('reason')->nullable();
            $t->string('risk')->default('low');              // low|medium|high
            $t->text('diff')->nullable();                    // unified diff (modify) — full content stored in workspace for create
            $t->text('tests')->nullable();                   // generated tests note/content ref
            $t->string('status')->default('proposed');       // proposed|approved|rejected|applied
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['ai_patch_run_id', 'status']);
        });

        // ── 25J repair loop ────────────────────────────────────────────────
        Schema::create('ai_repair_loops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignId('ai_patch_run_id')->nullable()->constrained('ai_patch_runs')->nullOnDelete();
            $t->string('test_command');                      // allowlisted template id
            $t->unsignedInteger('max_iterations')->default(3);
            $t->unsignedInteger('max_ai_calls')->default(6);
            $t->unsignedInteger('iterations')->default(0);
            $t->unsignedInteger('ai_calls')->default(0);
            $t->string('status')->default('running');        // running|passed|failed|limit_reached
            $t->json('history')->nullable();                 // [{iteration, exit_code, failures, action}]
            $t->timestamps();

            $t->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_repair_loops');
        Schema::dropIfExists('ai_patch_files');
        Schema::dropIfExists('ai_patch_runs');
        Schema::dropIfExists('copilot_runs');
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_model_profiles');
        Schema::dropIfExists('ai_provider_configs');
        Schema::dropIfExists('client_callsites');
        Schema::dropIfExists('client_repositories');
        Schema::table('migration_sources', function (Blueprint $t) {
            $t->dropForeign(['external_account_connection_id']);
            $t->dropColumn(['external_account_connection_id', 'management_project_ref', 'region', 'organization', 'capabilities']);
        });
        Schema::dropIfExists('external_account_connections');
    }
};
