<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 43 — Agent Runtime Platform (first runtime: OpenCode).
 *
 * Additive and idempotent, like every TEMM migration. Existing tables
 * (ai_provider_configs, projects, admin_audit_entries, …) are NOT touched:
 * a Developer Agent task is a NEW domain, not an AI-provider row.
 *
 * Safety lifecycle encoded here:
 *   task → isolated workspace (agent_workspaces) → events/commands captured
 *   (agent_task_events / agent_task_commands) → deterministic changeset with
 *   a content fingerprint (agent_changesets) → explicit human approval bound
 *   to that fingerprint (agent_approvals) → apply → verification result
 *   (agent_verifications).
 *
 * Deliberate normalisations (see docs/agent-runtime/DEVELOPER_AGENT.md):
 *  - Runtime model catalogues are NOT mirrored into a table. OpenCode reports
 *    providers/models live (`GET /config/providers`); a mirror would go stale.
 *    Only the canonical `provider/model` default is persisted per runtime/task.
 *  - A task maps 1:1 to one runtime session attempt in v1; the runtime session
 *    id lives on the task (`runtime_session_id`) next to an `attempts` counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_runtimes')) {
            Schema::create('agent_runtimes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('driver');                    // opencode (registry key, never branched on outside the adapter)
                $table->string('display_name');
                $table->string('mode');                      // managed|external
                $table->string('endpoint')->nullable();      // required for external; managed derives it
                $table->text('auth_secret_encrypted')->nullable(); // encrypted cast (basic-auth password)
                $table->boolean('enabled')->default(false);
                $table->string('status')->default('untested')->index(); // untested|connected|error
                $table->string('version')->nullable();       // reported by the runtime
                $table->json('capabilities')->nullable();    // normalized capability names
                $table->string('default_model')->nullable(); // canonical provider/model
                $table->unsignedInteger('timeout_seconds')->default(1800);
                $table->unsignedInteger('max_concurrent_tasks')->default(1);
                $table->unsignedInteger('workspace_retention_days')->default(7);
                $table->timestamp('last_tested_at')->nullable();
                $table->string('last_test_status')->nullable();  // passed|failed (safe classification only)
                $table->string('last_test_message')->nullable(); // classified message, never a raw wire dump
                $table->string('last_error_category')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_tasks')) {
            Schema::create('agent_tasks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('code')->unique();            // human-facing AGT-XXXX
                $table->unsignedBigInteger('created_by')->index();
                $table->unsignedBigInteger('workspace_id')->nullable()->index(); // owning client workspace
                $table->unsignedBigInteger('project_id')->index();
                $table->uuid('agent_runtime_id')->nullable()->index();
                $table->string('model')->nullable();          // canonical provider/model
                $table->text('prompt');                       // the task instruction (privacy policy: same as Nexus AI prompts)
                $table->string('title')->nullable();
                $table->string('status')->default('queued')->index();
                // queued|starting|running|awaiting_approval|applying|verifying
                // |completed|failed|cancelled|stale
                $table->string('base_revision')->nullable();  // authoritative source revision the workspace was cut from
                $table->uuid('agent_workspace_id')->nullable();
                $table->string('runtime_session_id')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('error_category')->nullable(); // RUNTIME_UNAVAILABLE, SESSION_FAILED, …
                $table->text('error_message')->nullable();    // safe, classified — never a secret
                $table->json('usage')->nullable();            // tokens/cost when the runtime reports them
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_workspaces')) {
            Schema::create('agent_workspaces', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->unsignedBigInteger('project_id')->index();
                $table->string('path');                      // absolute, inside TEMM's workspace root
                $table->string('base_revision');             // the revision it was cut from
                $table->string('status')->default('active')->index(); // active|released|cleaned
                $table->timestamp('retain_until')->nullable(); // retention/GC bookkeeping
                $table->timestamp('cleaned_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_task_events')) {
            Schema::create('agent_task_events', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->unsignedInteger('seq');
                $table->string('type');                      // normalized TEMM event type
                $table->text('summary')->nullable();         // user-safe summary (no hidden reasoning)
                $table->json('payload')->nullable();         // safe, bounded metadata
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_task_commands')) {
            Schema::create('agent_task_commands', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->text('command');
                $table->string('cwd')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->unsignedInteger('exit_code')->nullable();
                $table->unsignedBigInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('output_bytes')->default(0);
                $table->boolean('truncated')->default(false);
                $table->string('status')->default('running')->index(); // running|completed|failed|timeout|cancelled
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_changesets')) {
            Schema::create('agent_changesets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->string('base_revision');
                $table->string('fingerprint');               // sha256 over the canonical diff
                $table->longText('diff');                    // capped deterministic unified diff
                $table->unsignedInteger('files_added')->default(0);
                $table->unsignedInteger('files_modified')->default(0);
                $table->unsignedInteger('files_deleted')->default(0);
                $table->unsignedBigInteger('additions')->default(0);
                $table->unsignedBigInteger('deletions')->default(0);
                $table->boolean('truncated')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_approvals')) {
            Schema::create('agent_approvals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->string('changeset_fingerprint');     // approval binds to EXACTLY this content
                $table->string('base_revision');
                $table->unsignedBigInteger('approver_id');
                $table->string('status');                    // approved|rejected
                $table->text('note')->nullable();
                $table->timestamp('consumed_at')->nullable(); // single-use: set by a successful apply
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_verifications')) {
            Schema::create('agent_verifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('agent_task_id')->index();
                $table->string('status');                    // passed|failed|error
                $table->json('commands');                    // [{command, exit_code, duration_ms, truncated}]
                $table->text('summary')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'agent_verifications', 'agent_approvals', 'agent_changesets',
            'agent_task_commands', 'agent_task_events', 'agent_workspaces',
            'agent_tasks', 'agent_runtimes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
