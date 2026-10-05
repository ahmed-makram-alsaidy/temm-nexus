<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.6.0 Phase E — New Project + Migration journey.
 *
 * 1. project_wizard_drafts — server-side wizard state, persisted from the
 *    FIRST step so the "you can stop and finish later" promise is true
 *    (audit P0.4). One draft per user; safe values only (secrets are never
 *    written — they go to the project vault once a source exists).
 *
 * 2. migration_sources.last_tested_at — the Migration journey's Connect
 *    stage shows "last successful connection test"; that fact needs a
 *    stored timestamp, not a proxy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_wizard_drafts')) {
            Schema::create('project_wizard_drafts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique()->index();
                $table->unsignedInteger('step')->default(1);
                $table->json('state')->nullable();
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('analysis_id')->nullable();
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->json('test_result')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('migration_sources', 'last_tested_at')) {
            Schema::table('migration_sources', function (Blueprint $table) {
                $table->timestamp('last_tested_at')->nullable()->after('last_error');
            });
        }

        // 0.6.0 Phase E (§E7) — real analysis stage telemetry (Preparing →
        // Inspecting → Checking compatibility → Reviewing risks → Complete).
        // Stages and timestamps come from the engine, never the UI.
        if (! Schema::hasColumn('migration_analyses', 'telemetry')) {
            Schema::table('migration_analyses', function (Blueprint $table) {
                $table->json('telemetry')->nullable()->after('errors');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('migration_analyses', 'telemetry')) {
            Schema::table('migration_analyses', function (Blueprint $table) {
                $table->dropColumn('telemetry');
            });
        }

        if (Schema::hasColumn('migration_sources', 'last_tested_at')) {
            Schema::table('migration_sources', function (Blueprint $table) {
                $table->dropColumn('last_tested_at');
            });
        }

        Schema::dropIfExists('project_wizard_drafts');
    }
};
