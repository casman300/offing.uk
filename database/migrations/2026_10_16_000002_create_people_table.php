<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec §6: virtual people are first-class; `linked_user_id` is the
     * upgrade path to a real account later and stays null for now.
     *
     * Birthday parts are independent columns, not a date: day+month are
     * set together or not at all, and the year is optional (it can also be
     * the only part known — "born 1950, date unknown").
     */
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
