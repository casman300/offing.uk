<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Birthday reminders by email (#17, #18). Spec §6/§11.
     *
     * - users.reminders_enabled_at: the master switch (off by default). Only
     *   reminders due after this instant are ever sent, so switching on at
     *   21:00 never fires the 20:00 one retroactively.
     * - reminder_rules: per-user rules. For now two fixed slots
     *   (day_before / on_the_day); applies_to null = all of the user's
     *   people. Phase 2's editor adds rows, not columns.
     * - notification_outbox (never `notifications` — see CLAUDE.md): one row
     *   per person + birthday + rule, unique on dedupe_key so a send can
     *   never happen twice. Rows are written only once due; the drainer
     *   batches rows with the same user/rule/send_at into one email.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('reminders_enabled_at')->nullable()->after('timezone');
        });

        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 32)->nullable();
            $table->nullableMorphs('applies_to');
            $table->string('kind', 16)->default('notify');
            $table->json('offset_def');
            $table->string('channel', 16)->default('email');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'slot']);
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->string('dedupe_key')->unique();
            $table->json('payload');
            $table->timestamp('send_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('send_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
        Schema::dropIfExists('reminder_rules');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reminders_enabled_at');
        });
    }
};
