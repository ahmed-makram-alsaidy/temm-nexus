<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 32F — CDC checkpoint persistence.
 *
 * One row per (migration_run, item) capturing the incremental/CDC position:
 * snapshot boundary, source position, applied position, lag and the last
 * reconciliation state. The position payload is SIGNED (HMAC) so a
 * tampered checkpoint is detected on load (32C tamper-safety) and is
 * project-scoped by construction (runs belong to projects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cdc_checkpoints', function (Blueprint $table) {
            $table->id();
            // Logical reference to the owning run — checkpoints are
            // operational metadata and survive run pruning harmlessly.
            $table->unsignedBigInteger('migration_run_id')->index();
            $table->string('source_type', 40);
            $table->string('target_key', 190)->default('');
            $table->string('kind', 30);                 // checkpoint | incremental_watermark | cdc_position
            $table->json('position');                   // provider-specific position payload
            $table->string('signature', 64);            // HMAC-SHA256 over position (+ context)
            $table->unsignedBigInteger('applied_events')->default(0);
            $table->unsignedBigInteger('lag_events')->nullable();
            $table->json('last_reconciliation')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['migration_run_id', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cdc_checkpoints');
    }
};
