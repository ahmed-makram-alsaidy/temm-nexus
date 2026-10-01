<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 34 — Cutover Center persistence.
 *
 * cutover_plans: an ordered, reviewable cutover plan (34C) with per-gate
 * readiness states (34B) and a rollback plan (34E). cutover_approvals:
 * EXPLICIT human approval records per production-affecting gate (34D).
 * The platform never executes DNS/endpoint/provider changes itself — the
 * Cutover Center is a control room + audit trail, not an executor (34D/34F).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cutover_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('migration_run_id')->nullable()->index();
            $table->string('run_id')->unique();             // public uuid
            $table->string('status', 20)->default('draft'); // draft|ready|approved|in_window|completed|rolled_back|aborted
            $table->json('gates');                          // section => readiness state + evidence
            $table->json('steps');                          // ordered plan steps (34C)
            $table->json('rollback');                       // rollback plan (34E)
            $table->timestamp('cutover_at')->nullable();
            $table->timestamp('rollback_expiry')->nullable();
            $table->timestamps();
        });

        Schema::create('cutover_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutover_plan_id')->constrained('cutover_plans')->cascadeOnDelete();
            $table->string('gate', 60);                     // backup|data|cdc|client|endpoint_switch|...
            $table->string('decision', 20);                 // approved|rejected
            $table->text('note')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['cutover_plan_id', 'gate']);
        });

        Schema::create('cutover_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cutover_plan_id')->constrained('cutover_plans')->cascadeOnDelete();
            $table->string('event', 60);                    // plan_created|gate_checked|approval|step_recorded|rollback_recorded
            $table->json('details')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->index(['cutover_plan_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cutover_events');
        Schema::dropIfExists('cutover_approvals');
        Schema::dropIfExists('cutover_plans');
    }
};
