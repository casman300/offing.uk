<?php

namespace App\Feeds;

use App\Models\Event as CalendarEvent;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * The ICS a user's own feed token serves (spec §8, #15): birthdays, plus
 * the events on the user's own calendars. It's the user's own feed, so
 * private events are included (marked CLASS:PRIVATE); feeds for other
 * people will need to drop them (spec §7).
 *
 * Birthdays go out as one yearly-recurring all-day event per person, not
 * expanded occurrences, so the feed never runs out and doesn't wait on the
 * occurrence builder. The trade-off: no "turns 43" in the title, because
 * one recurring event can't carry a different age every year.
 *
 * Timed events go out in UTC (spec §10 allows it), so no VTIMEZONE blocks;
 * all-day events and birthdays are plain dates.
 *
 * Output must be byte-stable between requests when nothing has changed,
 * since the ETag is a hash of it. Nothing here may depend on "now" — which
 * is why every event sets its own UID and DTSTAMP: sabre's defaults for
 * both are random/current.
 */
final class UserFeed
{
    /** Undated-year birthdays start here. It's a leap year, so 29 Feb is a valid DTSTART. */
    private const UNKNOWN_YEAR = 2000;

    /** Default reminder rule (spec §9): 20:00 the day before, and 08:00 on the day. */
    private const ALERT_EVENING_BEFORE = '-PT240M';

    private const ALERT_MORNING_OF = 'PT480M';

    public function __construct(private readonly User $user) {}

    public function render(): string
    {
        $calendar = new VCalendar([
            'PRODID' => '-//Offing//offing.uk//EN',
            'NAME' => 'Offing',
            'X-WR-CALNAME' => 'Offing',
            'DESCRIPTION' => 'Birthdays and events from Offing',
            'X-WR-CALDESC' => 'Birthdays and events from Offing',
            'X-PUBLISHED-TTL' => 'PT1H',
        ]);
        $calendar->add('REFRESH-INTERVAL', 'PT1H', ['VALUE' => 'DURATION']);

        $people = $this->user->people()
            ->whereNotNull('birth_day')
            ->whereNotNull('birth_month')
            ->orderBy('id')
            ->get();

        foreach ($people as $person) {
            $this->addBirthday($calendar, $person);
        }

        $events = $this->user->events()
            ->orderBy('starts_at')
            ->orderBy('events.id')
            ->get();

        foreach ($events as $event) {
            $this->addEvent($calendar, $event);
        }

        return $calendar->serialize();
    }

    private function addBirthday(VCalendar $calendar, Person $person): void
    {
        $year = $person->birth_year ?? self::UNKNOWN_YEAR;
        $start = CarbonImmutable::create($year, $person->birth_month, $person->birth_day, 0, 0, 0, 'UTC');
        $stamp = CarbonImmutable::instance($person->updated_at ?? $person->created_at)->utc();

        // A plain yearly rule on 29 Feb skips non-leap years in every client.
        // "Last day of February" matches the app's own 28 Feb observance
        // (App\Support\Birthday::observedIn(); per-person choice is #28).
        $rule = $person->birth_month === 2 && $person->birth_day === 29
            ? 'FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1'
            : 'FREQ=YEARLY';

        /** @var VEvent $event */
        $event = $calendar->add('VEVENT', [
            'UID' => "person-{$person->id}-birthday@".self::uidHost(),
            'DTSTAMP' => $stamp,
            'SUMMARY' => "{$person->name}'s birthday 🎂",
        ]);
        $event->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
        $event->add('DTEND', $start->addDay()->format('Ymd'), ['VALUE' => 'DATE']);
        $event->add('TRANSP', 'TRANSPARENT');
        // Outlook's own all-day hint, alongside the standard VALUE=DATE.
        $event->add('X-MICROSOFT-CDO-ALLDAYEVENT', 'TRUE');
        $event->add('RRULE', $rule);

        if ($person->birth_year !== null) {
            $event->add('DESCRIPTION', "Born {$person->birth_year}.");
        }

        $event->add('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => "{$person->name}'s birthday is tomorrow",
            'TRIGGER' => self::ALERT_EVENING_BEFORE,
        ]);
        $event->add('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => "It's {$person->name}'s birthday today",
            'TRIGGER' => self::ALERT_MORNING_OF,
        ]);
    }

    private function addEvent(VCalendar $calendar, CalendarEvent $event): void
    {
        /** @var VEvent $ics */
        $ics = $calendar->add('VEVENT', [
            'UID' => $event->uid,
            'DTSTAMP' => CarbonImmutable::instance($event->updated_at ?? $event->created_at)->utc(),
            'SUMMARY' => $event->title,
        ]);

        if ($event->all_day) {
            $ics->add('DTSTART', $event->starts_at->utc()->format('Ymd'), ['VALUE' => 'DATE']);
            $ics->add('DTEND', $event->ends_at->utc()->format('Ymd'), ['VALUE' => 'DATE']);
            $ics->add('X-MICROSOFT-CDO-ALLDAYEVENT', 'TRUE');
        } else {
            $ics->add('DTSTART', $event->starts_at->utc());
            $ics->add('DTEND', $event->ends_at->utc());
        }

        if ($event->sequence > 0) {
            $ics->add('SEQUENCE', $event->sequence);
        }

        if ($event->description !== null) {
            $ics->add('DESCRIPTION', $event->description);
        }

        if ($event->location !== null) {
            $ics->add('LOCATION', $event->location);
        }

        if ($event->isPrivate()) {
            $ics->add('CLASS', 'PRIVATE');
        }

        if (! $event->isBusy()) {
            $ics->add('TRANSP', 'TRANSPARENT');
        }
    }

    private static function uidHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'offing.uk';
    }
}
