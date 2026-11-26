<?php

namespace App\Support\Calendar\Ics;

use App\Models\Event;
use App\Support\Calendar\Occurrences;
use App\Support\Feeds\FeedException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\MaxInstancesExceededException;
use Sabre\VObject\Recur\NoInstancesException;
use Throwable;

/**
 * Reads an iCalendar file into events (spec §5/§13), for subscriptions and
 * one-off imports alike. sabre/vobject does all the reading — timezones
 * (Windows/Outlook names and VTIMEZONEs included), dates, durations and
 * recurrence; never hand-rolled.
 *
 * - DTSTART/DTEND/DURATION → UTC instants plus the origin TZID; a floating
 *   time is taken as the reader's own zone; VALUE=DATE is all-day (end
 *   exclusive, like ours).
 * - RRULE kept as written (checked by expanding it once); EXDATEs keyed
 *   like ours; RECURRENCE-ID marks an override.
 * - CLASS:PRIVATE/CONFIDENTIAL → private; TRANSP:TRANSPARENT → free.
 * - STATUS:CANCELLED, or METHOD:CANCEL on the whole file, flags the event
 *   cancelled: callers skip it (a cancelled override skips its date).
 * - One bad event is reported and skipped, never the whole file; at most
 *   MAX_EVENTS are read.
 */
final class IcsParser
{
    public const MAX_EVENTS = 5000;

    /** Error lines kept for the preview/settings; the rest are counted. */
    private const MAX_ERRORS = 20;

    /** @var array<string, true>|null */
    private static ?array $zones = null;

    /**
     * @throws FeedException when it isn't a calendar at all
     */
    public static function parse(string $ics, string $floatingTzid, int $max = self::MAX_EVENTS): ParseResult
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (Throwable) {
            throw new FeedException('That isn’t an iCalendar (.ics) file Offing can read.');
        }

        if (! $calendar instanceof VCalendar) {
            throw new FeedException('That isn’t an iCalendar (.ics) file Offing can read.');
        }

        $cancelAll = strtoupper(trim((string) self::prop($calendar, 'METHOD'))) === 'CANCEL';
        $floating = new DateTimeZone(self::validZone($floatingTzid) ? $floatingTzid : 'UTC');

        /** @var array<string, ParsedEvent> $events */
        $events = [];
        $errors = [];
        $failed = 0;
        $truncated = false;

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (! $vevent instanceof VEvent) {
                continue;
            }

            if (count($events) >= $max) {
                $truncated = true;
                break;
            }

            try {
                $event = self::event($vevent, $floating, $cancelAll);
            } catch (Throwable $e) {
                $failed++;

                if (count($errors) < self::MAX_ERRORS) {
                    $title = self::text($vevent, 'SUMMARY', 80) ?? 'An event with no title';
                    $errors[] = "“{$title}” couldn’t be read: ".($e instanceof FeedException ? $e->getMessage() : 'it isn’t valid iCalendar.');
                }

                continue;
            }

            // The same event twice (some feeds repeat them): the latest SEQUENCE wins, then the later copy.
            $key = $event->sourceKey();

            if (! isset($events[$key]) || $event->sequence >= $events[$key]->sequence) {
                $events[$key] = $event;
            }
        }

        if ($failed > count($errors)) {
            $errors[] = 'and '.($failed - count($errors)).' more.';
        }

        if ($truncated) {
            $errors[] = 'Only the first '.number_format($max).' events were read; the rest were left out.';
        }

        $name = trim((string) (self::prop($calendar, 'X-WR-CALNAME') ?? self::prop($calendar, 'NAME') ?? ''));

        return new ParseResult(array_values($events), $errors, $truncated, $name === '' ? null : mb_substr($name, 0, 60));
    }

    private static function event(VEvent $vevent, DateTimeZone $floating, bool $cancelAll): ParsedEvent
    {
        $dtstart = self::prop($vevent, 'DTSTART');

        if (! $dtstart instanceof DateTimeProperty) {
            throw new FeedException('it has no start.');
        }

        $allDay = ! $dtstart->hasTime();

        if ($allDay) {
            $start = self::date($dtstart->getDateTime());
            $tzid = $floating->getName();
        } else {
            [$start, $tzid] = self::instant($dtstart, $floating);
        }

        $end = self::end($vevent, $start, $allDay, $floating);
        $rrule = self::rrule($vevent);

        $recurrenceAt = null;
        $recurrenceIsDate = false;
        $rid = self::prop($vevent, 'RECURRENCE-ID');

        if ($rid instanceof DateTimeProperty) {
            $recurrenceIsDate = ! $rid->hasTime();
            $recurrenceAt = $recurrenceIsDate ? self::date($rid->getDateTime()) : self::instant($rid, $floating)[0];
            // An edited date is a one-off; its series carries the rule.
            $rrule = null;
        }

        $uid = trim((string) self::prop($vevent, 'UID'));

        if ($uid === '') {
            // Stable across re-reads, so a re-sync updates rather than duplicates it.
            $uid = 'no-uid-'.sha1($start->toIso8601String().'|'.self::prop($vevent, 'SUMMARY')).'@import.offing.uk';
        }

        $status = strtoupper(trim((string) self::prop($vevent, 'STATUS')));
        $class = strtoupper(trim((string) self::prop($vevent, 'CLASS')));

        $event = new ParsedEvent(
            uid: mb_substr($uid, 0, 255),
            title: self::text($vevent, 'SUMMARY', 200) ?? '(No title)',
            description: self::text($vevent, 'DESCRIPTION', 10000, singleLine: false),
            location: self::text($vevent, 'LOCATION', 255),
            startsAt: $start,
            endsAt: $end,
            tzid: $tzid,
            allDay: $allDay,
            rrule: $rrule,
            exdates: $rrule === null ? [] : self::exdates($vevent, $allDay, $tzid, $start, $floating),
            private: in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true),
            free: strtoupper(trim((string) self::prop($vevent, 'TRANSP'))) === 'TRANSPARENT',
            sequence: max(0, min((int) (string) self::prop($vevent, 'SEQUENCE'), 1_000_000)),
            cancelled: $cancelAll || $status === 'CANCELLED',
            raw: $vevent->serialize(),
            recurrenceAt: $recurrenceAt,
            recurrenceIsDate: $recurrenceIsDate,
        );

        if ($rrule !== null) {
            self::checkExpands($event);
        }

        return $event;
    }

    /**
     * A timed property as a UTC instant and the IANA zone it was written
     * in. Floating times are the reader's zone; a zone PHP doesn't know by
     * name (an offset-only VTIMEZONE) keeps the instant, in UTC.
     *
     * @return array{0: CarbonImmutable, 1: string}
     */
    private static function instant(DateTimeProperty $property, DateTimeZone $floating): array
    {
        $dt = $property->isFloating() ? $property->getDateTime($floating) : $property->getDateTime();
        $zone = $dt->getTimezone()->getName();

        if ($zone === 'Z' || $zone === '+00:00') {
            $zone = 'UTC';
        }

        return [CarbonImmutable::instance($dt)->utc(), self::validZone($zone) ? $zone : 'UTC'];
    }

    /** A DATE value as midnight UTC, how all-day events are stored. */
    private static function date(DateTimeInterface $dt): CarbonImmutable
    {
        return CarbonImmutable::parse($dt->format('Y-m-d'), 'UTC');
    }

    private static function end(VEvent $vevent, CarbonImmutable $start, bool $allDay, DateTimeZone $floating): CarbonImmutable
    {
        $dtend = self::prop($vevent, 'DTEND');

        if ($dtend instanceof DateTimeProperty) {
            $end = $allDay ? self::date($dtend->getDateTime()) : self::instant($dtend, $floating)[0];
        } elseif (($duration = self::prop($vevent, 'DURATION')) instanceof Property\ICalendar\Duration) {
            $end = $start->add($duration->getDateInterval());
        } else {
            // RFC 5545: no end means one day for a date, an instant for a time.
            $end = $allDay ? $start->addDay() : $start;
        }

        if ($allDay && $end <= $start) {
            return $start->addDay();
        }

        return $end < $start ? $start : $end;
    }

    private static function rrule(VEvent $vevent): ?string
    {
        $property = self::prop($vevent, 'RRULE');

        if ($property === null) {
            return null;
        }

        $value = $property->getValue();
        $rule = strtoupper(trim(is_array($value) ? implode(';', $value) : (string) $value));

        if ($rule === '') {
            return null;
        }

        if (strlen($rule) > 255) {
            throw new FeedException('its repeat rule is too long.');
        }

        return $rule;
    }

    /**
     * EXDATEs as our keys: dates for an all-day series, else wall times in
     * the series' zone. A date-only EXDATE on a timed series skips that
     * day's occurrence.
     *
     * @return list<string>
     */
    private static function exdates(VEvent $vevent, bool $allDay, string $tzid, CarbonImmutable $start, DateTimeZone $floating): array
    {
        $keys = [];
        $zone = new DateTimeZone($tzid);

        foreach ($vevent->select('EXDATE') as $property) {
            if (! $property instanceof DateTimeProperty) {
                continue;
            }

            $isDate = ! $property->hasTime();

            foreach ($property->getDateTimes($property->isFloating() ? $floating : null) as $dt) {
                if (! $dt instanceof DateTimeImmutable) {
                    continue;
                }

                if ($allDay) {
                    $keys[] = $isDate ? $dt->format('Y-m-d') : $dt->setTimezone($zone)->format('Y-m-d');
                } elseif ($isDate) {
                    $keys[] = $dt->format('Y-m-d').'T'.$start->setTimezone($tzid)->format('H:i:s');
                } else {
                    $keys[] = $dt->setTimezone($zone)->format('Y-m-d\TH:i:s');
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** A rule sabre can't expand would break every view it's in: refuse it here instead. */
    private static function checkExpands(ParsedEvent $parsed): void
    {
        $event = new Event;
        $event->forceFill([
            'uid' => $parsed->uid,
            'starts_at' => $parsed->startsAt,
            'ends_at' => $parsed->endsAt,
            'tzid' => $parsed->tzid,
            'all_day' => $parsed->allDay,
            'rrule' => $parsed->rrule,
            'exdates' => $parsed->exdates,
        ]);
        $event->setRelation('overrides', new EloquentCollection);

        try {
            $iterator = new EventIterator(Occurrences::vevent($event), null, new DateTimeZone('UTC'));
            $iterator->valid();
        } catch (NoInstancesException|MaxInstancesExceededException) {
            // Every date skipped, or a huge series: still valid, the views cope.
        } catch (Throwable) {
            throw new FeedException('its repeat rule isn’t valid.');
        }
    }

    private static function text(VEvent $vevent, string $name, int $max, bool $singleLine = true): ?string
    {
        $property = self::prop($vevent, $name);

        if ($property === null) {
            return null;
        }

        $value = (string) $property;

        if ($singleLine) {
            $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** A component's first property of that name — typed, unlike sabre's magic `->NAME`. */
    private static function prop(Component $component, string $name): ?Property
    {
        foreach ($component->select($name) as $node) {
            if ($node instanceof Property) {
                return $node;
            }
        }

        return null;
    }

    private static function validZone(string $tzid): bool
    {
        self::$zones ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);

        return isset(self::$zones[$tzid]);
    }
}
