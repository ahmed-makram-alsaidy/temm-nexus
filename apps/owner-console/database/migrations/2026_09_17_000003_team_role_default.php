<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20V hardening: team membership must be explicit. NULL cp_role means
 * "no team access" (fail-closed); only seeded/assigned roles enter the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('is_admin', true)->update(['cp_role' => 'owner']);
        Schema::table('users', function (Blueprint $t) {
            $t->string('cp_role', 30)->nullable()->default(null)->change();
        });
        // Any non-admin row that inherited the old 'owner' default loses it.
        DB::table('users')->where('is_admin', false)->where('cp_role', 'owner')->update(['cp_role' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('cp_role', 30)->default('owner')->change();
        });
    }
};
