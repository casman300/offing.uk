<?php

use App\Support\Birthday;
use Carbon\CarbonImmutable;

function day(string $date, string $tz = 'UTC'): CarbonImmutable
{
    return CarbonImmutable::parse($date, $tz);
}

describe('next occurrence and days until', function () {
    it('counts today as the next birthday', function () {
        $b = new Birthday(16, 10, 1983);

        expect($b->nextOccurrence(day('2026-10-16'))->toDateString())->toBe('2026-10-16')
            ->and($b->daysUntil(day('2026-10-16')))->toBe(0);
    });

    it('rolls to next year once this year has passed', function () {
        $b = new Birthday(15, 10, null);

        expect($b->nextOccurrence(day('2026-10-16'))->toDateString())->toBe('2027-10-15')
            ->and($b->daysUntil(day('2026-10-16')))->toBe(364);
    });

    it('has no next birthday without a date', function () {
        expect((new Birthday(null, null, 1950))->nextOccurrence(day('2026-10-16')))->toBeNull()
            ->and((new Birthday(null, null, null))->daysUntil(day('2026-10-16')))->toBeNull();
    });

    it('observes 29 February on 28 February in non-leap years', function () {
        $b = new Birthday(29, 2, 2000);

        expect($b->nextOccurrence(day('2026-10-16'))->toDateString())->toBe('2027-02-28')
            ->and($b->nextOccurrence(day('2027-10-16'))->toDateString())->toBe('2028-02-29')
            ->and($b->nextOccurrence(day('2027-02-28'))->toDateString())->toBe('2027-02-28')
            ->and($b->nextOccurrence(day('2027-03-01'))->toDateString())->toBe('2028-02-29');
    });

    it('compares dates, not instants, whatever the viewer timezone', function () {
        // 00:30 on the birthday in London during BST is 23:30 UTC the day before.
        $today = CarbonImmutable::parse('2026-07-02 00:30', 'Europe/London');
        $b = new Birthday(2, 7, 1990);

        expect($b->daysUntil($today))->toBe(0)
            ->and($b->age($today))->toBe(36);
    });
});

describe('age', function () {
    it('is one less before this year\'s birthday', function () {
        $b = new Birthday(2, 10, 1983);

        expect($b->age(day('2026-10-01')))->toBe(42)
            ->and($b->age(day('2026-10-02')))->toBe(43)
            ->and($b->turns(day('2026-10-01')))->toBe(43)
            ->and($b->turns(day('2026-10-02')))->toBe(43)
            ->and($b->turns(day('2026-10-03')))->toBe(44);
    });

    it('ticks over on 28 February for leaplings in non-leap years', function () {
        $b = new Birthday(29, 2, 2000);

        expect($b->age(day('2027-02-27')))->toBe(26)
            ->and($b->age(day('2027-02-28')))->toBe(27)
            ->and($b->age(day('2028-02-28')))->toBe(27)
            ->and($b->age(day('2028-02-29')))->toBe(28);
    });

    it('is unknown without a year', function () {
        $b = new Birthday(2, 10, null);

        expect($b->age(day('2026-10-16')))->toBeNull()
            ->and($b->turns(day('2026-10-16')))->toBeNull()
            ->and($b->approximateAge(day('2026-10-16')))->toBeNull();
    });

    it('is approximate (year maths only) for year-only people', function () {
        $b = new Birthday(null, null, 1950);

        expect($b->isYearOnly())->toBeTrue()
            ->and($b->age(day('2026-10-16')))->toBeNull()
            ->and($b->approximateAge(day('2026-10-16')))->toBe(76);
    });
});

describe('year from age', function () {
    it('matches the worked example on #13', function () {
        // On 29 Sep 2026, a 2 Oct birthday at age 42 → born 1983.
        expect(Birthday::yearFromAge(42, 2, 10, day('2026-09-29')))->toBe(1983);
    });

    it('does not subtract once the birthday has happened, including today', function () {
        expect(Birthday::yearFromAge(43, 2, 10, day('2026-10-02')))->toBe(1983)
            ->and(Birthday::yearFromAge(43, 1, 1, day('2026-10-16')))->toBe(1983);
    });

    it('round-trips with age()', function (string $today, int $d, int $m, int $age) {
        $year = Birthday::yearFromAge($age, $d, $m, day($today));

        expect((new Birthday($d, $m, $year))->age(day($today)))->toBe($age);
    })->with([
        ['2026-10-16', 16, 10, 0],
        ['2026-10-16', 17, 10, 12],
        ['2026-10-16', 15, 10, 12],
        ['2026-01-01', 31, 12, 99],
        ['2026-12-31', 1, 1, 5],
    ]);

    it('can land a leapling on a non-leap year (form validation catches it)', function () {
        // On 1 Mar 2026, a 29 Feb birthday at 25 → 2001, which had no 29 Feb.
        $year = Birthday::yearFromAge(25, 29, 2, day('2026-03-01'));

        expect($year)->toBe(2001)
            ->and(Birthday::isValidDate(29, 2, $year))->toBeFalse();
    });
});

describe('date validity', function () {
    it('allows 29 February without a year, or in a leap year', function () {
        expect(Birthday::isValidDate(29, 2, null))->toBeTrue()
            ->and(Birthday::isValidDate(29, 2, 2000))->toBeTrue()
            ->and(Birthday::isValidDate(29, 2, 1900))->toBeFalse()
            ->and(Birthday::isValidDate(31, 4, null))->toBeFalse()
            ->and(Birthday::isValidDate(30, 2, null))->toBeFalse();
    });
});
