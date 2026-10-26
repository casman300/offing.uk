<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Push notifications for reminders (spec §6/§14).
     *
     * - user_devices: one row per browser/device that allowed notifications.
     *   endpoint_hash (sha256 of the push endpoint) stops the same device
     *   being added twice; push_subscription holds the endpoint + keys.
     * - users.reminder_delivery: email | push (email as backup) | both.
     * - notification_outbox.delivered_via: what actually went out, for the
     *   admin Outbox (e.g. "push", "email", "email (push fallback)").
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('endpoint_hash', 64)->unique();
            $table->json('push_subscription');
            $table->json('settings_override')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('reminder_delivery', 16)->default('email')->after('reminders_enabled_at');
        });

        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->string('delivered_via', 32)->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropColumn('delivered_via');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reminder_delivery');
        });

        Schema::dropIfExists('user_devices');
    }
};
