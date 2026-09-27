<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 26.1H — deployment doctor run history. This is the real, forward-only
// schema change shipped in 0.1.0-rc.2 (upgrade verification); the doctor
// records diagnostic run summaries here. No secret values are ever stored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_doctor_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('exit_code');
            $table->unsignedInteger('pass')->default(0);
            $table->unsignedInteger('warning')->default(0);
            $table->unsignedInteger('fail')->default(0);
            $table->unsignedInteger('not_configured')->default(0);
            $table->unsignedInteger('not_applicable')->default(0);
            $table->string('platform_version', 40)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_doctor_runs');
    }
};
