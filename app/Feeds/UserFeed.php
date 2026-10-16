<?php

namespace App\Feeds;

use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\IcalendarGenerator\Components\Alert;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Enums\RecurrenceFrequency;
use Spatie\IcalendarGenerator\ValueObjects\RRule;

/**
 * The ICS a user's own feed token serves (spec §8, #15). Today that's
 * birthdays only; events join once they exist.
 *
 * Birthdays go out as one yearly-recurring all-day event per person, not
 * expanded occurrences, so the feed never runs out and doesn't wait on the
 * occurrence builder. The trade-off: no "turns 43" in the title, because
 * one recurring event can't carry a different age every year.
 *
 * Output must be byte-stable between requests when nothing has changed,
 * since the ETag is a hash of it. Nothing here may depend on "now".
 */
final class UserFeed
{
    /** Undated-year birthdays start here. It's a leap year, so 29 Feb is a valid DTSTART. */
    private const UNKNOWN_YEAR = 2000;

    /** Default reminder rule (spec §9): 20:00 the day before, and 08:00 on the day. */
    private const ALERT_EVENING_BEFORE_MINUTES = 4 * 60;

    private const ALERT_MORNING_OF_MINUTES = 8 * 60;

    public function __construct(private readonly User $user) {}

    public function render(): string
    {
        $calendar = Calendar::create('Offing')
            ->description('Birthdays from Offing')
            ->productIdentifier('-//Offing//offing.uk//EN')
            ->refreshInterval(60)
            ->withoutTimezone();

        $people = $this->user->people()
            ->whereNotNull('birth_day')
            ->whereNotNull('birth_month')
            ->orderBy('id')
            ->get();

        foreach ($people as $person) {
            $calendar->event($this->birthday($person));
        }

        return $calendar->get();
    }

    private function birthday(Person $person): Event
    {
        $year = $person->birth_year ?? self::UNKNOWN_YEAR;
        $start = CarbonImmutable::create($year, $person->birth_month, $person->birth_day, 0, 0, 0, 'UTC');

        // A plain yearly rule on 29 Feb skips non-leap years in every client.
        // "Last day of February" matches the app's own 28 Feb observance
        // (App\Support\Birthday::observedIn(); per-person choice is #28).
        $rule = RRule::frequency(RecurrenceFrequency::Yearly);
        if ($person->birth_month === 2 && $person->birth_day === 29) {
            $rule->onMonth(2)->onMonthDay(-1);
        }

        $event = Event::create("{$person->name}'s birthday 🎂")
            ->uniqueIdentifier("person-{$person->id}-birthday@".self::uidHost())
            ->createdAt($person->updated_at ?? $person->created_at)
            ->startsAt($start, withTime: false)
            ->endsAt($start->addDay(), withTime: false)
            ->fullDay()
            ->transparent()
            ->rrule($rule)
            ->alert(Alert::minutesBeforeStart(self::ALERT_EVENING_BEFORE_MINUTES, "{$person->name}'s birthday is tomorrow"))
            ->alert(Alert::minutesAfterStart(self::ALERT_MORNING_OF_MINUTES, "It's {$person->name}'s birthday today"));

        if ($person->birth_year !== null) {
            $event->description("Born {$person->birth_year}.");
        }

        return $event;
    }

    private static function uidHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'offing.uk';
    }
}
