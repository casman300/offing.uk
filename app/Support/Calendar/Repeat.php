<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * The common repeat patterns the event form offers (spec §5): daily,
 * weekly (on chosen days), monthly or yearly, every N, ending never, after
 * N times or on a date. Anything richer — say, an imported RRULE — is kept
 * as written and shown as "custom"; sabre still expands it.
 */
final class Repeat
{
    public const FREQS = ['daily' => 'DAILY', 'weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];

    public const DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * @param  array{freq: string, interval?: int|null, days?: list<string>|null, ends?: string|null, count?: int|null, until?: string|null}  $repeat
     * @param  CarbonImmutable  $start  the event's start, in its own zone
     */
    public static function rrule(array $repeat, CarbonImmutable $start, bool $allDay, string $tzid): ?string
    {
        $freq = self::FREQS[$repeat['freq']] ?? null;

        if ($freq === null) {
            return null;
        }

        $parts = ['FREQ='.$freq];
        $interval = max(1, (int) ($repeat['interval'] ?? 1));

        if ($interval > 1) {
            $parts[] = 'INTERVAL='.$interval;
        }

        if ($freq === 'WEEKLY') {
            $days = array_values(array_intersect(self::DAYS, $repeat['days'] ?? []));
            // The start's own weekday is always one of them (RFC 5545 counts DTSTART anyway).
            $own = self::DAYS[$start->dayOfWeekIso - 1];

            if ($days !== [] && $days !== [$own]) {
                $parts[] = 'BYDAY='.implode(',', array_values(array_unique([...$days, $own])));
            }
        }

        $ends = $repeat['ends'] ?? 'never';

        if ($ends === 'count' && ! empty($repeat['count'])) {
            $parts[] = 'COUNT='.max(1, (int) $repeat['count']);
        } elseif ($ends === 'until' && ! empty($repeat['until'])) {
            // Inclusive of the whole last day. With a TZID'd DTSTART, UNTIL must be UTC (RFC 5545 §3.3.10).
            $parts[] = 'UNTIL='.($allDay
                ? CarbonImmutable::parse($repeat['until'])->format('Ymd')
                : CarbonImmutable::parse($repeat['until'], $tzid)->endOfDay()->utc()->format('Ymd\THis\Z'));
        }

        return implode(';', self::sortDays($parts));
    }

    /**
     * Back from an RRULE to the form's fields, or `custom` when the form
     * can't say it.
     *
     * @return array{freq: string, interval: int, days: list<string>, ends: string, count: int|null, until: string|null, custom: string|null}
     */
    public static function fields(?string $rrule, string $tzid, bool $allDay): array
    {
        $fields = ['freq' => 'none', 'interval' => 1, 'days' => [], 'ends' => 'never', 'count' => null, 'until' => null, 'custom' => null];

        if ($rrule === null || $rrule === '') {
            return $fields;
        }

        $rule = [];
        foreach (explode(';', strtoupper($rrule)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $rule[$key] = $value;
        }

        $freq = array_search($rule['FREQ'] ?? '', self::FREQS, true);
        $known = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'WKST'];
        if ($freq === 'weekly') {
            $known[] = 'BYDAY';
        }
        $days = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];

        if ($freq === false || array_diff(array_keys($rule), $known) !== [] || array_diff($days, self::DAYS) !== []) {
            return ['custom' => $rrule] + $fields;
        }

        $fields['freq'] = (string) $freq;
        $fields['interval'] = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $fields['days'] = array_values(array_intersect(self::DAYS, $days));

        if (isset($rule['COUNT'])) {
            $fields['ends'] = 'count';
            $fields['count'] = (int) $rule['COUNT'];
        } elseif (isset($rule['UNTIL'])) {
            $until = CarbonImmutable::parse($rule['UNTIL'], 'UTC');
            $fields['ends'] = 'until';
            $fields['until'] = ($allDay || strlen($rule['UNTIL']) === 8 ? $until : $until->setTimezone($tzid))->toDateString();
        }

        return $fields;
    }

    /**
     * @param  list<string>  $parts
     * @return list<string>
     */
    private static function sortDays(array $parts): array
    {
        return array_map(function (string $part) {
            if (! str_starts_with($part, 'BYDAY=')) {
                return $part;
            }
            $days = explode(',', substr($part, 6));
            usort($days, fn ($a, $b) => array_search($a, self::DAYS, true) <=> array_search($b, self::DAYS, true));

            return 'BYDAY='.implode(',', $days);
        }, $parts);
    }

    /** "Every 2 weeks on Mon, Thu until 5 Mar 2027" — for the event page. */
    public static function describe(?string $rrule, string $tzid, bool $allDay): ?string
    {
        if ($rrule === null) {
            return null;
        }

        $f = self::fields($rrule, $tzid, $allDay);

        if ($f['custom'] !== null) {
            return 'Repeats (custom pattern)';
        }

        $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month', 'yearly' => 'year'][$f['freq']];
        $text = $f['interval'] === 1
            ? ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month', 'yearly' => 'Every year'][$f['freq']]
            : "Every {$f['interval']} {$unit}s";

        if ($f['days'] !== []) {
            $names = ['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'];
            $text .= ' on '.implode(', ', array_map(fn ($d) => $names[$d], $f['days']));
        }

        if ($f['ends'] === 'count') {
            $text .= ", {$f['count']} times";
        } elseif ($f['ends'] === 'until' && $f['until'] !== null) {
            $text .= ' until '.CarbonImmutable::parse($f['until'])->format('j M Y');
        }

        return $text;
    }
}
