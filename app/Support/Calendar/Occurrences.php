<?php

namespace App\Support\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;

/**
 * Turns events into the occurrences that fall in a window (spec §5/§10).
 *
 * Recurrence is always sabre's job (CLAUDE.md: never hand-roll RRULE): each
 * event becomes a VEVENT in its origin timezone and sabre's EventIterator
 * expands it there, so "09:00 every Monday in London" stays at 09:00 across
 * the clocks changing. All-day events are dates and expand in UTC.
 */
final class Occurrences
{
    /** A window this long holds a daily event's every occurrence for a few years — plenty for any view. */
    private const MAX_PER_EVENT = 1000;

    /**
     * @param  iterable<Event>  $events
     * @return Collection<int, Occurrence> sorted by start
     */
    public static function between(iterable $events, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $occurrences = collect();

        foreach ($events as $event) {
            foreach (self::of($event, $from, $to) as $occurrence) {
                $occurrences->push($occurrence);
            }
        }

        return $occurrences->sortBy(fn (Occurrence $o) => [$o->start->getTimestamp(), $o->event->id])->values();
    }

    /**
     * @return list<Occurrence>
     */
    public static function of(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $event->isRecurring()) {
            return $event->starts_at < $to && $event->ends_at > $from
                ? [new Occurrence($event, $event->starts_at->utc(), $event->ends_at->utc())]
                : [];
        }

        try {
            $iterator = new EventIterator(self::vevent($event), null, new DateTimeZone('UTC'));
            $iterator->fastForward($from->toDateTimeImmutable());
        } catch (NoInstancesException|MaxInstancesExceededException) {
            return [];
        }

        $found = [];

        while ($iterator->valid() && count($found) < self::MAX_PER_EVENT) {
            $start = CarbonImmutable::instance($iterator->getDtStart())->utc();

            if ($start >= $to) {
                break;
            }

            $end = CarbonImmutable::instance($iterator->getDtEnd())->utc();

            if ($end > $from) {
                $found[] = new Occurrence($event, $start, $end);
            }

            $iterator->next();
        }

        return $found;
    }

    /**
     * The event as a sabre VEVENT: DTSTART/DTEND in its own zone (or as
     * dates), plus its RRULE and EXDATEs. For expanding, dates edited on
     * their own are EXDATEs too; a feed leaves them out and sends the edits
     * as RECURRENCE-ID overrides instead.
     */
    public static function vevent(Event $event, bool $withOverrides = true): VEvent
    {
        $calendar = new VCalendar;
        /** @var VEvent $vevent */
        $vevent = $calendar->add('VEVENT', ['UID' => $event->uid]);

        if ($event->all_day) {
            $vevent->add('DTSTART', $event->starts_at->utc()->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->ends_at->utc()->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $zone = new DateTimeZone($event->tzid);
            $vevent->add('DTSTART', $event->starts_at->toDateTimeImmutable()->setTimezone($zone));
            $vevent->add('DTEND', $event->ends_at->toDateTimeImmutable()->setTimezone($zone));
        }

        if ($event->rrule !== null) {
            $vevent->add('RRULE', $event->rrule);
        }

        // Dates edited on their own are separate events; the series skips them.
        $skipped = [...($event->exdates ?? []), ...($withOverrides ? $event->overrides->pluck('recurrence_id')->filter()->all() : [])];

        foreach (array_values(array_unique($skipped)) as $exdate) {
            if ($event->all_day) {
                $vevent->add('EXDATE', str_replace('-', '', substr($exdate, 0, 10)), ['VALUE' => 'DATE']);
            } else {
                $vevent->add('EXDATE', new DateTimeImmutable($exdate, new DateTimeZone($event->tzid)));
            }
        }

        return $vevent;
    }

    /**
     * The occurrence of a series starting at `key` (as written in `exdates`),
     * or null when the series has no such date.
     */
    public static function find(Event $event, string $key): ?Occurrence
    {
        if (! $event->isRecurring() || preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?$/', $key) !== 1) {
            return null;
        }

        $start = $event->all_day ? CarbonImmutable::parse(substr($key, 0, 10), 'UTC') : CarbonImmutable::parse($key, $event->tzid);

        return collect(self::of($event, $start->subMinute(), $start->addMinute()))
            ->first(fn (Occurrence $o) => self::exdateKey($event, $o->start) === $key);
    }

    /**
     * How an occurrence's start is written in `exdates`: its local wall time
     * in the event's zone, or its date when all-day.
     */
    public static function exdateKey(Event $event, CarbonImmutable $start): string
    {
        return $event->all_day
            ? $start->utc()->format('Y-m-d')
            : $start->setTimezone($event->tzid)->format('Y-m-d\TH:i:s');
    }
}
