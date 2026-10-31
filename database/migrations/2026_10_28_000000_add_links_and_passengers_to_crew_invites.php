<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more kinds of crew invite (spec §7, "Getting aboard"):
 *
 * - Copied links (for WhatsApp and the like): no email, so `email` becomes
 *   nullable, and `name` says who the captain made it for.
 * - "Give Gimli an account": `crew_member_id` is the passenger row that
 *   accepting turns into that account's membership, history and all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_invites', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('name', 100)->nullable()->after('email');
            $table->foreignId('crew_member_id')->nullable()->after('crew_id')->constrained('crew_members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crew_member_id');
            $table->dropColumn('name');
        });
    }
};
