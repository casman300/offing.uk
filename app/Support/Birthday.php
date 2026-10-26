<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Birthday arithmetic for a person's (day, month, year?) parts.
 *
 * All "today" values are calendar dates in the viewer's timezone — birthdays
 * are dates, not instants (spec §10), so nothing here touches time of day.
 *
 * 29 February birthdays are observed on 28 February in non-leap years, both
 * for "days until" and for when the age ticks over.
 *
 * Mirrored client-side in resources/js/lib/birthday.ts — keep them in step.
 */
final class Birthday
{
    public const MIN_YEAR = 1900;

    public function __construct(
        public readonly ?int $day,
        public readonly ?int $month,
        public readonly ?int $year,
    ) {}

    public function hasDate(): bool
    {
        return $this->day !== null && $this->month !== null;
    }

    public function isYearOnly(): bool
    {
        return ! $this->hasDate() && $this->year !== null;
    }

    /**
     * The date this birthday is observed on in the given year.
     */
    public function observedIn(int $year): ?CarbonImmutable
    {
        if (! $this->hasDate()) {
            return null;
        }

        $day = $this->day;

        if ($this->month === 2 && $day === 29 && ! self::isLeapYear($year)) {
            $day = 28;
        }

        return CarbonImmutable::create($year, $this->month, $day, 0, 0, 0, 'UTC');
    }

    /**
     * The next time this birthday is observed, counting today as "next".
     */
    public function nextOccurrence(CarbonImmutable $today): ?CarbonImmutable
    {
        $today = self::dateOnly($today);
        $thisYear = $this->observedIn($today->year);

        if ($thisYear === null) {
            return null;
        }

        return $thisYear->gte($today) ? $thisYear : $this->observedIn($today->year + 1);
    }

    public function daysUntil(CarbonImmutable $today): ?int
    {
        $next = $this->nextOccurrence($today);

        return $next === null ? null : (int) self::dateOnly($today)->diffInDays($next);
    }

    /**
     * Exact age today. Needs the full date — a year alone can't say whether
     * this year's birthday has happened yet (see approximateAge()).
     */
    public function age(CarbonImmutable $today): ?int
    {
        if (! $this->hasDate() || $this->year === null) {
            return null;
        }

        return $today->year - $this->year - ($this->hasHappenedThisYear($today) ? 0 : 1);
    }

    /**
     * Year-only people: current year − birth year, shown as "~76". Off by
     * one until their (unknown) birthday passes; that's the accepted cost.
     */
    public function approximateAge(CarbonImmutable $today): ?int
    {
        return $this->isYearOnly() ? $today->year - $this->year : null;
    }

    /**
     * The age they turn on their next birthday (today included).
     */
    public function turns(CarbonImmutable $today): ?int
    {
        $next = $this->nextOccurrence($today);

        return $next === null || $this->year === null ? null : $next->year - $this->year;
    }

    /**
     * Age → birth year: current year − age, minus one more if this year's
     * birthday hasn't happened yet. Requires day+month by design — without
     * them the answer is a coin-flip between two years.
     */
    public static function yearFromAge(int $age, int $day, int $month, CarbonImmutable $today): int
    {
        $birthday = new self($day, $month, null);

        return $today->year - $age - ($birthday->hasHappenedThisYear($today) ? 0 : 1);
    }

    /**
     * Whether (day, month[, year]) is a real calendar date. With no year,
     * 29 February is allowed — it exists in some year.
     */
    public static function isValidDate(int $day, int $month, ?int $year): bool
    {
        return checkdate($month, $day, $year ?? 2000);
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    /**
     * Strip time and zone so dates compare as dates: midnight in London
     * during BST is still 23:00 UTC the day before.
     */
    public static function dateOnly(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, 'UTC');
    }

    private function hasHappenedThisYear(CarbonImmutable $today): bool
    {
        return self::dateOnly($today)->gte($this->observedIn($today->year));
    }
}
