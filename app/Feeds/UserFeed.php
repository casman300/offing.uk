<?php

namespace App\Feeds;

use App\Features\Calendar as CalendarFeature;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\Person;
use App\Models\User;
use App\Support\Calendar\Occurrences;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Throwable;

/**
 * The ICS a user's own feed token serves (spec §8, #15): birthdays, plus —
 * once the calendar is on for them — the events on every calendar they can
 * see. Events go out as their series (RRULE/EXDATE, with edited dates as
 * RECURRENCE-ID overrides) in their own timezone, with a VTIMEZONE for each
 * zone used; someone else's private event is just "Busy" (§7).
 *
 * An imported event (a subscription, or a one-off .ics import) carries
 * its original VEVENT in `raw_ical`; everything in it Offing doesn't
 * model itself — X- properties, ATTACH, ATTENDEE, VALARMs, RDATE… — goes
 * back out as it came in (§5: round-trip-safe), unless it's a Busy block.
 *
 * Birthdays go out as one yearly-recurring all-day event per person, not
 * expanded occurrences, so the feed never runs out and doesn't wait on the
 * occurrence builder. The trade-off: no "turns 43" in the title, because
 * one recurring event can't carry a different age every year.
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

    /** Properties this feed writes itself from the event row; everything else in `raw_ical` is copied through. */
    private const OWN_PROPERTIES = [
        'UID', 'DTSTAMP', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'EXDATE', 'RECURRENCE-ID',
        'SUMMARY', 'LOCATION', 'DESCRIPTION', 'TRANSP', 'CLASS', 'SEQUENCE', 'X-MICROSOFT-CDO-ALLDAYEVENT',
    ];

    public function __construct(private readonly User $user) {}

    public function render(): string
    {
        $events = $this->events();
        $description = $events === null ? 'Birthdays from Offing' : 'Your calendar and birthdays from Offing';

        $calendar = new VCalendar([
            'PRODID' => '-//Offing//offing.uk//EN',
            'NAME' => 'Offing',
            'X-WR-CALNAME' => 'Offing',
            'DESCRIPTION' => $description,
            'X-WR-CALDESC' => $description,
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

        if ($events !== null && $events->isNotEmpty()) {
            $this->addEvents($calendar, $events);
        }

        return $calendar->serialize();
    }

    /**
     * Every event on the calendars the user can see, oldest first; null when
     * the calendar isn't on for them, so a crewmate's events don't surface a
     * dark feature in their feed.
     *
     * @return Collection<int, Event>|null
     */
    private function events(): ?Collection
    {
        if (! Feature::for($this->user)->active(CalendarFeature::class)) {
            return null;
        }

        $calendarIds = Calendar::query()->visibleTo($this->user)->pluck('id');

        return Event::query()
            ->whereIn('calendar_id', $calendarIds)
            ->where(fn ($q) => $q->whereNull('series_id')->orWhereHas('series'))
            ->with(['overrides:id,series_id,recurrence_id', 'series:id,uid,all_day,tzid'])
            ->orderBy('id')
            ->get()
            ->reject(fn (Event $event) => ! $event->detailsVisibleTo($this->user) && $event->transparency === Event::FREE)
            ->values();
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function addEvents(VCalendar $calendar, Collection $events): void
    {
        $zones = [];
        $lastYear = 0;

        foreach ($events as $event) {
            if (! $event->all_day) {
                $year = (int) $event->starts_at->setTimezone($event->tzid)->format('Y');
                $zones[$event->tzid] = min($zones[$event->tzid] ?? PHP_INT_MAX, $year);
                $lastYear = max($lastYear, $year);
            }
        }

        VTimezones::add($calendar, $zones, $lastYear);

        foreach ($events as $event) {
            $this->addEvent($calendar, $event);
        }
    }

    private function addEvent(VCalendar $calendar, Event $event): void
    {
        $visible = $event->detailsVisibleTo($this->user);
        $series = $event->isOverride() ? $event->series : null;

        // Overrides are their own RECURRENCE-ID events, not holes in the series.
        $vevent = Occurrences::vevent($event, withOverrides: false);
        // Sabre stamps a random UID and the current DTSTAMP; the feed must be byte-stable.
        $vevent->remove('UID');
        $vevent->remove('DTSTAMP');
        $calendar->add($vevent);

        $vevent->add('UID', $series !== null ? $series->uid : $event->uid);
        $vevent->add('DTSTAMP', CarbonImmutable::instance($event->updated_at ?? $event->created_at)->utc());
        $vevent->add('SEQUENCE', (string) $event->sequence);

        if ($series !== null && $event->recurrence_id !== null) {
            if ($series->all_day) {
                $vevent->add('RECURRENCE-ID', str_replace('-', '', substr($event->recurrence_id, 0, 10)), ['VALUE' => 'DATE']);
            } else {
                $vevent->add('RECURRENCE-ID', new \DateTimeImmutable($event->recurrence_id, new \DateTimeZone($series->tzid)));
            }
        }

        $vevent->add('SUMMARY', $visible ? $event->title : 'Busy');

        if ($visible && $event->location !== null) {
            $vevent->add('LOCATION', $event->location);
        }

        if ($visible && $event->description !== null) {
            $vevent->add('DESCRIPTION', $event->description);
        }

        $vevent->add('TRANSP', $event->transparency === Event::FREE ? 'TRANSPARENT' : 'OPAQUE');

        if ($event->isPrivate()) {
            $vevent->add('CLASS', 'PRIVATE');
        }

        if ($event->all_day) {
            $vevent->add('X-MICROSOFT-CDO-ALLDAYEVENT', 'TRUE');
        }

        if ($visible && $event->raw_ical !== null) {
            self::copyUnknown($vevent, $event->raw_ical);
        }
    }

    /** Re-emits what Offing didn't understand from an imported VEVENT (§5). */
    private static function copyUnknown(VEvent $vevent, string $raw): void
    {
        try {
            $document = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Offing//raw//EN\r\n".rtrim($raw)."\r\nEND:VCALENDAR\r\n", Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            return;
        }

        $source = $document->select('VEVENT')[0] ?? null;

        if (! $source instanceof VEvent) {
            return;
        }

        foreach ($source->children() as $child) {
            if ($child instanceof Property && in_array(strtoupper($child->name), self::OWN_PROPERTIES, true)) {
                continue;
            }

            $vevent->add(clone $child);
        }
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

    private static function uidHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'offing.uk';
    }
}
