<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beta testers get Rollout::Beta flags (and anything wider). Ticked per
 * user on /admin/users. Unlike admin rights, which only ever come from
 * ADMIN_EMAILS, this is ordinary data: it grants features, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_beta_tester')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_beta_tester');
        });
    }
};
