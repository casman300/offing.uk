<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §6. Calendars are owned by a morph (user now, group later) so
     * club calendars need no schema change.
     *
     * Events store UTC instants plus the IANA zone they were created in
     * (spec §10). All-day events are dates: starts_at/ends_at hold midnight
     * UTC of the first day and of the day after the last (iCal's exclusive
     * DTEND), and are never shifted into anyone's timezone.
     *
     * rrule/exdates/raw_ical/source/feed_id are here so recurrence and
     * inbound feeds don't need a second migration; nothing writes them yet.
     */
    public function up(): void
    {
        Schema::create('calendars', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('name');
            $table->string('colour', 7)->nullable();
            $table->string('slug');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'slug']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->string('uid')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('tzid', 64);
            $table->boolean('all_day')->default(false);
            $table->text('rrule')->nullable();
            $table->json('exdates')->nullable();
            $table->string('visibility', 16)->default('normal');
            $table->string('transparency', 8)->default('busy');
            $table->string('source', 8)->default('local');
            $table->unsignedBigInteger('feed_id')->nullable();
            $table->longText('raw_ical')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['calendar_id', 'deleted_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('calendars');
    }
};
