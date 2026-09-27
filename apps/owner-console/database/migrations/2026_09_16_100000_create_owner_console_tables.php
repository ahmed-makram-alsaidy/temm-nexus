<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registry of hosted products. Secrets are NEVER stored here —
        // only names/references. Credentials live in each project's .env.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable();
            $table->string('api_domain')->nullable();
            $table->string('status')->default('planned');
            $table->string('db_name')->nullable();
            $table->string('redis_prefix')->nullable();
            $table->string('storage_disk')->default('local');
            $table->string('deploy_status')->default('not_deployed');
            $table->timestamps();
        });

        Schema::create('infrastructure_events', function (Blueprint $table) {
            $table->id();
            $table->string('severity', 16)->default('info');
            $table->string('source');
            $table->text('message');
            $table->jsonb('context')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_records', function (Blueprint $table) {
            $table->id();
            $table->string('db_name');
            $table->string('status')->default('unknown');
            $table->bigInteger('size_bytes')->nullable();
            $table->string('checksum')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('restore_test_status')->default('not_tested');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
        Schema::dropIfExists('backup_records');
        Schema::dropIfExists('infrastructure_events');
        Schema::dropIfExists('projects');
    }
};
