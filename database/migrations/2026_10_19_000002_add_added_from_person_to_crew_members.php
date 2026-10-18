<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A passenger added from someone's own People is a crew-owned *copy*
     * (spec §6: the crew gets the name, never your notes). This remembers
     * which of your people it came from, so the picker can say "already
     * aboard" and re-adding reuses the copy instead of making another.
     * Only ever read for the person's owner; crewmates never see it.
     */
    public function up(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->foreignId('added_from_person_id')->nullable()->after('person_id')->constrained('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_from_person_id');
        });
    }
};
