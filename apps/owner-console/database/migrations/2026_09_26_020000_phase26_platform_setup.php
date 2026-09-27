<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 26D/26E — first-run setup state. Server-side source of truth for
// platform initialization; also the generic key/value store for operator
// settings that must survive container recreation (values encrypted at rest).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            // Encrypted at rest (model cast) — safe for non-secret UI labels
            // and mandatory for anything sensitive the wizard persists.
            $table->text('value')->nullable();
            $table->boolean('secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
