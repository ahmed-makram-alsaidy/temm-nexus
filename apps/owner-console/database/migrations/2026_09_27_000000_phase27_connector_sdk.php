<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 27I — connector traceability columns.
 *
 * Sources and analyses refer to connectors by stable key + semantic version
 * (27F.2, 27I.2) so every historical artifact remains attributable to the
 * exact connector that produced it. No provider-specific columns are added;
 * provider detail stays in the existing JSON `connection`/`capabilities`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_sources', function (Blueprint $table) {
            $table->string('connector_key')->nullable()->after('type');
            $table->string('connector_version', 32)->nullable()->after('connector_key');
            $table->string('connector_instance_id')->nullable()->after('connector_version')
                ->comment('Connector-scoped instance reference (e.g. account connection id).');
            $table->index('connector_key');
        });

        // 27I.1 — backfill: existing sources map 1:1 onto their connector.
        // Quoting is driver-specific (MySQL backticks vs ANSI double quotes).
        $typeColumn = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql'
            ? \Illuminate\Support\Facades\DB::raw('"type"')
            : \Illuminate\Support\Facades\DB::raw('`type`');
        \Illuminate\Support\Facades\DB::table('migration_sources')
            ->whereNull('connector_key')
            ->update(['connector_key' => $typeColumn]);

        Schema::table('migration_analyses', function (Blueprint $table) {
            $table->string('connector_key')->nullable()->after('migration_source_id');
            $table->string('connector_version', 32)->nullable()->after('connector_key');
            $table->string('analysis_version', 32)->nullable()->after('connector_version')
                ->comment('Normalized analysis artifact schema version (27I.2).');
        });
    }

    public function down(): void
    {
        Schema::table('migration_sources', function (Blueprint $table) {
            $table->dropIndex(['connector_key']);
            $table->dropColumn(['connector_key', 'connector_version', 'connector_instance_id']);
        });
        Schema::table('migration_analyses', function (Blueprint $table) {
            $table->dropColumn(['connector_key', 'connector_version', 'analysis_version']);
        });
    }
};
