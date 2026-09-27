<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable-style admin action trail. No update/delete paths exist in the UI.
        Schema::create('admin_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('project_slug')->nullable()->index();
            $table->string('action', 64)->index();
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('environment')->default('local');
            $table->string('timezone')->default('UTC');
            $table->string('locale')->default('en');
            $table->boolean('maintenance_mode')->default(false);
            $table->timestamp('last_deployed_at')->nullable();
            $table->string('health_status', 16)->default('unknown');
            $table->string('api_version')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::table('backup_records', function (Blueprint $table) {
            $table->string('type', 16)->default('full');
            $table->string('location', 16)->default('local');
            $table->timestamp('verified_at')->nullable();
            $table->text('log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_records', function (Blueprint $table) {
            $table->dropColumn(['type', 'location', 'verified_at', 'log']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'environment', 'timezone', 'locale', 'maintenance_mode',
                'last_deployed_at', 'health_status', 'api_version', 'notes',
            ]);
        });
        Schema::dropIfExists('admin_audit_entries');
    }
};
