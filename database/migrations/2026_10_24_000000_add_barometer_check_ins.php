<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barometer check-ins (#36, spec §26): a content-free "time for a quick
 * check-in" about 30 minutes after a reading, so the second rating gets
 * filled in.
 *
 * - notification_outbox.kind: the outbox now carries two kinds of message.
 *   Existing rows are birthday reminders; check-ins have their own drainer.
 * - users.barometer_check_ins: the per-user off switch. On by default —
 *   only users with the Barometer flag ever get one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->string('kind', 32)->default('birthday')->after('user_id');
            $table->index(['kind', 'status', 'send_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('barometer_check_ins')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('barometer_check_ins');
        });

        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status', 'send_at']);
            $table->dropColumn('kind');
        });
    }
};
