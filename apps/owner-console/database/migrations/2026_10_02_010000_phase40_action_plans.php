<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.4.0 Phase J — persistent AI ACTION plans.
 *
 * A plan is the unit of permissioned mutation for Nexus AI. The model can
 * only PROPOSE one; a human approves THE PLAN (never free-form arguments),
 * and execution re-checks authority against the immutable row. Because the
 * client only ever sends a plan id, there is no path to alter arguments,
 * scope, or target after the fact — "changing the arguments" means creating
 * a new plan, which means a new approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_action_plans')) {
            return;
        }

        Schema::create('ai_action_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();       // who asked (the conversation's user)
            $table->unsignedBigInteger('approved_by')->nullable(); // who clicked Approve
            $table->string('action');                              // registry key — no dynamic calls
            $table->string('scope');                               // platform|workspace|project
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->json('arguments');          // sanitized, validated at propose time
            $table->text('intent');             // human-readable what & why
            $table->json('affected');           // affected resources, safe descriptors
            $table->string('risk');             // low|moderate|high
            $table->string('fingerprint')->nullable(); // state the plan was built on
            $table->text('verification');       // how success will be verified
            $table->string('status')->default('pending')->index();
            // pending|approved|verified|verification_failed|failed|rejected|expired|stale
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable(); // safe execution + verification result
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_plans');
    }
};
