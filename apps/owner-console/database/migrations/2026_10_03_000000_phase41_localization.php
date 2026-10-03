<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.4.0-rc.5 (Phase 41) — localization + AI settings closure.
 *
 * Additive only; existing installations upgrade without data loss:
 *
 *   - users.locale: per-user UI language (nullable = inherit platform
 *     default). Never touches workspace/project/CDC structures.
 *   - ai_provider_configs.last_tested_at / last_test_message: "Test
 *     Connection" history for the Settings → Nexus AI page. The message is
 *     a SAFE, human-readable classification — never a raw exception or
 *     anything containing key material.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('locale', 10)->nullable()->after('job_title');
                $table->index('locale');
            });
        }

        if (! Schema::hasColumn('ai_provider_configs', 'last_tested_at')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->timestamp('last_tested_at')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('ai_provider_configs', 'last_test_message')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->string('last_test_message', 120)->nullable()->after('last_tested_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_provider_configs', 'last_test_message')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->dropColumn('last_test_message');
            });
        }
        if (Schema::hasColumn('ai_provider_configs', 'last_tested_at')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->dropColumn('last_tested_at');
            });
        }
        if (Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['locale']);
                $table->dropColumn('locale');
            });
        }
    }
};
