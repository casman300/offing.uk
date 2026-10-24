<?php

namespace App\Support\Barometer;

use App\Features\Barometer;
use App\Models\BarometerReading;
use App\Models\OutboxMessage;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Barometer check-ins (#36, spec §26): about 30 minutes after a reading,
 * a content-free nudge — "time for a quick check-in" — so the second rating
 * gets filled in while it still means something.
 *
 * Queued when a reading is saved (from the app or an AI assistant), as a
 * notification_outbox row holding only the reading's id; CheckInDrainer
 * re-checks everything at send time.
 */
final class CheckIns
{
    public const DELAY_MINUTES = 30;

    /** A reading that happened longer ago than this when saved is a backfill: no check-in. */
    public const BACKFILL_MINUTES = 60;

    /** Later than this after it was due, a check-in isn't worth sending. */
    public const GIVE_UP_MINUTES = 120;

    /**
     * Queue a check-in for a newly saved reading, if it should get one.
     * Keyed on the reading, so it can only ever be queued once.
     */
    public static function queueFor(BarometerReading $reading): bool
    {
        if (! self::wanted($reading)) {
            return false;
        }

        $now = now();

        return OutboxMessage::query()->insertOrIgnore([
            'user_id' => $reading->user_id,
            'kind' => OutboxMessage::KIND_BAROMETER_CHECK_IN,
            'channel' => 'auto',
            'dedupe_key' => "barometer-check-in:{$reading->id}",
            'payload' => json_encode(['reading_id' => $reading->id]),
            'send_at' => $now->copy()->addMinutes(self::DELAY_MINUTES),
            'status' => OutboxMessage::PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    private static function wanted(BarometerReading $reading): bool
    {
        // Already has its second rating, or a backfill (no time, or long ago): nothing to check in about.
        if ($reading->rating_after !== null || $reading->occurred_at === null
            || $reading->occurred_at->isBefore(now()->subMinutes(self::BACKFILL_MINUTES))) {
            return false;
        }

        $user = User::find($reading->user_id);

        return $user !== null && $user->barometer_check_ins && Feature::for($user)->active(Barometer::class);
    }
}
