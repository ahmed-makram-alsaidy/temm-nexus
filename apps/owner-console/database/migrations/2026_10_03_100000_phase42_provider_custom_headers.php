<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.4.0-rc.7 (Phase 42) — provider custom HTTP headers.
 *
 * Additive only. `ai_provider_configs.custom_headers` holds a small,
 * strictly validated map of extra HTTP headers for providers that require
 * client identification (e.g. AgentRouter's mandatory User-Agent). Values
 * are encrypted at rest by the model cast (`encrypted:array`) and are never
 * re-displayed after save — same posture as the API key. The transport
 * additionally refuses Authorization / Host / Content-Length / Cookie at
 * send time regardless of stored state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ai_provider_configs', 'custom_headers')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->text('custom_headers')->nullable()->after('secret_encrypted');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_provider_configs', 'custom_headers')) {
            Schema::table('ai_provider_configs', function (Blueprint $table) {
                $table->dropColumn('custom_headers');
            });
        }
    }
};
