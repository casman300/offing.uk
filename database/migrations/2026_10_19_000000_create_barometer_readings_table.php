<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Barometer readings (#35). Spec §25.
     *
     * One column per header of the CBT anxiety-tracking form — fixed on
     * purpose, not a template engine. The free text is health data and is
     * stored encrypted (the model's `encrypted` cast), so those columns are
     * `text` regardless of the 5,000-character input limit. Ratings stay
     * plain integers so stats can aggregate them in SQL.
     *
     * `local_date` is the day in `tzid` when it happened, written on save:
     * day grouping, exports and stats then never do timezone maths.
     *
     * No soft deletes: deleting a health record means it's gone.
     */
    public function up(): void
    {
        Schema::create('barometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('tzid', 64);
            $table->date('local_date');
            $table->text('situation')->nullable();
            $table->text('thoughts')->nullable();
            $table->text('sensations')->nullable();
            $table->unsignedTinyInteger('rating_before');
            $table->text('coping_action')->nullable();
            $table->text('self_talk')->nullable();
            $table->unsignedTinyInteger('rating_after')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'local_date']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barometer_readings');
    }
};
