<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 35.6 — normalized CDC stream telemetry on checkpoints. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cdc_checkpoints', function (Blueprint $t) {
            $t->string('stream_status', 20)->nullable()->after('errors');
            $t->timestamp('last_event_at')->nullable()->after('stream_status');
            $t->json('stream_telemetry')->nullable()->after('last_event_at');
            $t->index(['stream_status']);
        });
    }

    public function down(): void
    {
        Schema::table('cdc_checkpoints', function (Blueprint $t) {
            $t->dropIndex(['stream_status']);
            $t->dropColumn(['stream_status', 'last_event_at', 'stream_telemetry']);
        });
    }
};
