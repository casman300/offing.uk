<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The calendar (spec §6, Phase 1b).
     *
     * - calendars: owned by a user (private) or a crew (every crewmate sees
     *   it) — shared by who owns it, never per event (§7). `editors` is the
     *   §7 switch: all crew, or captains only.
     * - events: start/end stored in UTC with the origin IANA `tzid`;
     *   recurrence is an RRULE expanded in that zone by sabre (§10). All-day
     *   events store midnight-UTC dates, end exclusive, like iCal's DTEND.
     *   `exdates` holds skipped occurrence starts as local wall times
     *   (Y-m-d\TH:i:s, or Y-m-d when all-day). `visibility` (normal|private)
     *   and `transparency` (busy|free) are independent flags (§7).
     *   `raw_ical` keeps an imported VEVENT for round-trip fidelity (§5).
     *   An event with `series_id` replaces one date of that series (iCal's
     *   RECURRENCE-ID): `recurrence_id` is the replaced occurrence's start,
     *   keyed like `exdates`.
     */
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('name', 60);
            $table->string('colour', 16);
            $table->string('editors', 16)->default('crew');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('recurrence_id', 20)->nullable();
            $table->string('uid')->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('tzid', 64);
            $table->boolean('all_day')->default(false);
            $table->string('rrule')->nullable();
            $table->json('exdates')->nullable();
            $table->string('visibility', 16)->default('normal');
            $table->string('transparency', 8)->default('busy');
            $table->string('source', 8)->default('local');
            $table->longText('raw_ical')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['calendar_id', 'starts_at']);
            $table->unique(['series_id', 'recurrence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('calendars');
    }
};
