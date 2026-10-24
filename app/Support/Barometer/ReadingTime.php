<?php

namespace App\Support\Barometer;

use Carbon\CarbonImmutable;

/**
 * When a Barometer reading happened (spec §26), shared by the app's form and
 * the AI assistant's `log_reading` so both accept the same things: a day
 * (Y-m-d) and an optional time (H:i), in the user's timezone. No time means
 * "not recorded" — a worry remembered from an earlier day.
 */
final class ReadingTime
{
    /** A little slack for clock skew between phone and server. */
    public const FUTURE_SLACK_MINUTES = 5;

    /** Null when the time wasn't recorded. */
    public static function occurredAt(string $date, ?string $time, string $timezone): ?CarbonImmutable
    {
        if ($time === null || $time === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $timezone)->utc();
    }

    /**
     * Why this day/time can't be logged — it's in the future — as
     * [field, message], or null if it's fine. Assumes both are well-formed.
     *
     * @return array{0: 'date'|'time', 1: string}|null
     */
    public static function problem(string $date, ?string $time, string $timezone): ?array
    {
        $occurredAt = self::occurredAt($date, $time, $timezone);

        if ($occurredAt === null) {
            return $date > now($timezone)->toDateString() ? ['date', 'That day is in the future.'] : null;
        }

        return $occurredAt->isAfter(now()->addMinutes(self::FUTURE_SLACK_MINUTES)) ? ['time', 'That time is in the future.'] : null;
    }
}
