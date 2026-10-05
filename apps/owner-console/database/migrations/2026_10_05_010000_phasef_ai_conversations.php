<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.6.0 Phase F — persistent Nexus AI conversations.
 *
 * The chat transcript used to live only in the Livewire component, so a
 * browser refresh silently erased the conversation. Phase F gives every
 * conversation a durable home, per user and per resolved scope:
 *
 *   - `ai_conversations` — WHO (user_id) talked at WHICH scope about WHICH
 *     object, with a human title derived from the first question and a
 *     last_active cursor for the history list. Scope columns mirror the
 *     `ai_action_plans` convention (nullable workspace/project ids), so the
 *     history list can honestly filter to "conversations in this context"
 *     without leaking anything across tenants.
 *
 *   - `ai_messages` — the transcript itself. Only user/assistant text,
 *     humanised tool activity (names + labels + ok/denied + duration — never
 *     raw tool payloads), proposed action CARDS (pending state — the plan row
 *     itself stays authoritative), and the provider/model route that answered.
 *
 * Secrets never enter these tables: user text is prompt content (already
 * forbidden to include credentials by the engine's rules), tool payloads are
 * reduced to safe metadata at the dispatcher, and no provider key material
 * is ever stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('scope');                              // platform|workspace|project
            $table->unsignedBigInteger('workspace_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('title');                              // derived from the first question
            $table->timestamp('last_active_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'scope', 'last_active_at']);
            $table->index(['user_id', 'project_id', 'last_active_at']);
            $table->index(['user_id', 'workspace_id', 'last_active_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ai_conversation_id')->index();
            $table->string('role');                               // user|assistant
            $table->text('text');
            $table->json('tools')->nullable();                    // humanised tool activity + safe metadata
            $table->json('actions')->nullable();                  // proposed action cards (display state)
            $table->json('route')->nullable();                    // provider/model that answered (assistant rows)
            // A user row that is still awaiting a successful answer carries the
            // classified failure so a refresh shows the honest state, and a
            // retry re-uses THIS row instead of duplicating the question.
            $table->json('error')->nullable();
            $table->timestamps();

            $table->foreign('ai_conversation_id')
                ->references('id')->on('ai_conversations')
                ->cascadeOnDelete();
            $table->index(['ai_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
