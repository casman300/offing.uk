<?php

namespace App\Feeds;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;

/**
 * VTIMEZONE components for the zones a feed's events use (RFC 5545 §3.6.5),
 * built from PHP's own tz database: one STANDARD/DAYLIGHT block per real
 * transition between the earliest event and 2037 (or later, if an event
 * starts later). Explicit transitions rather than RRULEs, so a zone whose
 * rules changed over the years is still right for every date it covers.
 *
 * Deterministic — no "now" — so the feed stays byte-stable for its ETag.
 */
final class VTimezones
{
    private const LAST_YEAR = 2037;

    /**
     * @param  array<string, int>  $zones  tzid => earliest year an event uses it
     */
    public static function add(VCalendar $calendar, array $zones, int $lastEventYear): void
    {
        ksort($zones);

        foreach ($zones as $tzid => $fromYear) {
            self::addZone($calendar, $tzid, max(1970, $fromYear), max(self::LAST_YEAR, $lastEventYear + 1));
        }
    }

    private static function addZone(VCalendar $calendar, string $tzid, int $fromYear, int $toYear): void
    {
        $zone = new DateTimeZone($tzid);
        $begin = (new DateTimeImmutable("{$fromYear}-01-01", $zone))->getTimestamp();
        $end = (new DateTimeImmutable(($toYear + 1).'-01-01', $zone))->getTimestamp();
        $transitions = $zone->getTransitions($begin, $end) ?: [];

        /** @var Component $vtimezone */
        $vtimezone = $calendar->add('VTIMEZONE', ['TZID' => $tzid]);

        // The first entry is the state at $begin, not a transition.
        $first = array_shift($transitions);
        $offset = (int) ($first['offset'] ?? 0);
        $vtimezone->add($calendar->createComponent($first['isdst'] ?? false ? 'DAYLIGHT' : 'STANDARD', [
            'DTSTART' => gmdate('Ymd\THis', $begin + $offset),
            'TZOFFSETFROM' => self::offset($offset),
            'TZOFFSETTO' => self::offset($offset),
            'TZNAME' => (string) ($first['abbr'] ?? $tzid),
        ], false));

        foreach ($transitions as $transition) {
            $to = (int) $transition['offset'];
            // createComponent, not add(): sabre would make an unknown name a property.
            $vtimezone->add($calendar->createComponent($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD', [
                // Local wall time just before the change, in the old offset.
                'DTSTART' => gmdate('Ymd\THis', (int) $transition['ts'] + $offset),
                'TZOFFSETFROM' => self::offset($offset),
                'TZOFFSETTO' => self::offset($to),
                'TZNAME' => (string) $transition['abbr'],
            ], false));
            $offset = $to;
        }
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
