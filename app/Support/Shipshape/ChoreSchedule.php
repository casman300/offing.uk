<?php

namespace App\Support\Shipshape;

use App\Models\Chore;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Recur\RRuleIterator;

/**
 * Date maths for chores (spec §25). Chores are dates, not times, so all of
 * this works on midnight-UTC dates and never meets a DST boundary.
 *
 * - Floating: the clock restarts when it's done — `every` `unit` later.
 * - Fixed: the next date in an RRULE anchored on `anchor_on`, expanded by
 *   sabre (CLAUDE.md: never hand-roll RRULE). A monthly rule anchored on the
 *   31st follows RFC 5545 and skips months without one.
 */
final class ChoreSchedule
{
    private const FREQ = ['day' => 'DAILY', 'week' => 'WEEKLY', 'month' => 'MONTHLY', 'year' => 'YEARLY'];

    /**
     * A calendar date as midnight UTC, whatever timezone it arrived in —
     * "today in London" and a stored due date then compare correctly.
     */
    public static function date(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), 'UTC');
    }

    public static function rrule(int $every, string $unit): string
    {
        return 'FREQ='.self::FREQ[$unit].';INTERVAL='.max(1, $every);
    }

    public static function addInterval(CarbonImmutable $date, int $every, string $unit): CarbonImmutable
    {
        $every = max(1, $every);

        return match ($unit) {
            'day' => $date->addDays($every),
            'week' => $date->addWeeks($every),
            // No overflow: a floating "monthly" done on 31 Jan is due 28/29 Feb.
            'month' => $date->addMonthsNoOverflow($every),
            'year' => $date->addYearsNoOverflow($every),
        };
    }

    /**
     * The first date in a fixed chore's pattern strictly after $after, or
     * null when it has no anchor yet ("needs a date").
     */
    public static function nextFixedAfter(Chore $chore, CarbonImmutable $after): ?CarbonImmutable
    {
        if ($chore->anchor_on === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $iterator = new RRuleIterator(
            $chore->rrule ?: self::rrule($chore->every, $chore->unit),
            new DateTimeImmutable($chore->anchor_on->toDateString(), $utc),
        );
        $iterator->fastForward(new DateTimeImmutable($after->addDay()->toDateString(), $utc));

        $next = $iterator->current();

        return $next === null ? null : CarbonImmutable::parse($next->format('Y-m-d'));
    }

    /**
     * Where the due date goes after this chore is done or skipped.
     *
     * Fixed chores land on the next pattern date after whichever is later:
     * today, or the date it was due — so ticking Monday's bins early still
     * means next week's Tuesday, and three missed weeks don't pile up.
     * Floating chores count from the day it was done; a skip counts from
     * when it was due (or today, if that's later).
     */
    public static function nextDue(Chore $chore, string $kind, CarbonImmutable $doneOn, CarbonImmutable $today): ?CarbonImmutable
    {
        $today = self::date($today);
        $doneOn = self::date($doneOn);
        $due = $chore->due_on === null ? null : self::date($chore->due_on);
        $base = $due !== null && $due->greaterThan($today) ? $due : $today;

        if ($chore->isFixed()) {
            return self::nextFixedAfter($chore, $base);
        }

        return self::addInterval($kind === 'done' ? $doneOn : $base, $chore->every, $chore->unit);
    }
}
