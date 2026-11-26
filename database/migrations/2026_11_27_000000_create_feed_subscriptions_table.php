<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inbound calendars (spec §6/§13, Phase 1b).
     *
     * - feed_subscriptions: an external ICS URL a user subscribes to. The URL
     *   is often a secret (Google's "secret address"), so it's stored
     *   encrypted and only its host is ever shown or logged. Fetch state:
     *   `etag` for conditional GET, `last_hash` to skip unchanged bodies,
     *   and `failures`/`next_fetch_at`/`last_error` for back-off.
     * - calendars.feed_subscription_id: each subscription shows up as one
     *   read-only calendar owned by the subscriber, so the views, toggles
     *   and §7 visibility rules treat it like any other calendar.
     * - events.feed_subscription_id: which subscription an event came from
     *   (source=feed). `external_uid` is the UID in the source file — kept
     *   apart from `uid`, which stays globally unique even when two people
     *   subscribe to the same feed or a file is imported twice.
     */
    public function up(): void
    {
        Schema::create('feed_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('host');
            $table->string('name', 60);
            $table->string('colour', 16);
            $table->string('etag')->nullable();
            $table->string('last_hash', 64)->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('last_error')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->timestamp('next_fetch_at')->nullable();
            $table->unsignedInteger('event_count')->default(0);
            $table->timestamps();

            $table->index('next_fetch_at');
        });

        Schema::table('calendars', function (Blueprint $table) {
            $table->foreignId('feed_subscription_id')->nullable()->after('editors')->constrained()->cascadeOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('feed_subscription_id')->nullable()->after('source')->constrained()->cascadeOnDelete();
            $table->string('external_uid')->nullable()->after('uid');

            $table->index(['calendar_id', 'external_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['calendar_id', 'external_uid']);
            $table->dropConstrainedForeignId('feed_subscription_id');
            $table->dropColumn('external_uid');
        });

        Schema::table('calendars', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feed_subscription_id');
        });

        Schema::dropIfExists('feed_subscriptions');
    }
};
