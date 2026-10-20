<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipshape — household chores (spec §25).
     *
     * - chore_lists: the sharing unit. Owned by a user (private) or a crew
     *   (every crewmate sees it); `editors` is the §7 switch — all crew, or
     *   captains only. Responding (ticking) is always open to viewers.
     * - chores: every chore repeats. Floating = `every` `unit` after it was
     *   done; fixed = an RRULE anchored on `anchor_on`, expanded by sabre.
     *   One live `due_on` (a date, never a time); null = "needs a date".
     *   `version` bumps on every change so a stale tick is refused.
     * - chore_rota: who takes turns, in order; `rota_position` points into it.
     * - chore_entries: the ship's log. Append-only apart from voiding; names
     *   and the title are snapshots, `before` is the chore's state the entry
     *   replaced so voiding the latest one can put it back.
     */
    public function up(): void
    {
        Schema::create('chore_lists', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('name', 60);
            $table->string('editors', 16)->default('crew');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_list_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->string('area', 40)->nullable();
            $table->text('notes')->nullable();
            $table->string('mode', 16);
            $table->unsignedSmallInteger('every')->default(1);
            $table->string('unit', 8);
            $table->string('rrule')->nullable();
            $table->date('anchor_on')->nullable();
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->string('assign', 8)->default('anyone');
            $table->foreignId('assignee_member_id')->nullable()->constrained('crew_members')->nullOnDelete();
            $table->unsignedSmallInteger('rota_position')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['chore_list_id', 'deleted_at']);
            $table->index('due_on');
        });

        Schema::create('chore_rota', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crew_member_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');

            $table->unique(['chore_id', 'crew_member_id']);
        });

        Schema::create('chore_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->date('done_on');
            $table->foreignId('done_by_member_id')->nullable()->constrained('crew_members')->nullOnDelete();
            $table->string('done_by_name', 255);
            $table->string('chore_title', 100);
            $table->foreignId('logged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('via', 8)->default('app');
            $table->string('reason', 200)->nullable();
            $table->json('before');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 32)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['chore_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chore_entries');
        Schema::dropIfExists('chore_rota');
        Schema::dropIfExists('chores');
        Schema::dropIfExists('chore_lists');
    }
};
