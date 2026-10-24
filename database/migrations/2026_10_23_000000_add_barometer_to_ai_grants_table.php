<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barometer's own switch on an AI connection (spec §12, §26): health data
 * sits outside the access levels, so it's a separate opt-in, off by default.
 * When on, the connection may log readings and read aggregate stats — never
 * a reading's details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_grants', function (Blueprint $table) {
            $table->boolean('barometer')->default(false)->after('include_private');
        });
    }

    public function down(): void
    {
        Schema::table('ai_grants', function (Blueprint $table) {
            $table->dropColumn('barometer');
        });
    }
};
