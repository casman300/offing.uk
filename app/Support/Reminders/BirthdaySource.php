<?php

namespace App\Support\Reminders;

use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Upcoming dates in a window" for one user, worked out on the fly from
 * `people` — no occurrences table (#16 moved to Phase 1b). Phase 1b adds an
 * events source alongside this one; the scanner and outbox stay the same.
 */
final class BirthdaySource
{
    /**
     * Each birthday observed between $from and $to (local dates, inclusive),
     * 29 Feb falling on 28 Feb in non-leap years.
     *
     * @return iterable<array{person: Person, date: CarbonImmutable, turns: ?int}>
     */
    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $people = $user->people()->whereNotNull('birth_day')->whereNotNull('birth_month')->get();

        foreach ($people as $person) {
            $birthday = $person->birthday();

            for ($year = $from->year; $year <= $to->year; $year++) {
                $date = $birthday->observedIn($year);

                if ($date !== null && $date->betweenIncluded($from, $to)) {
                    yield [
                        'person' => $person,
                        'date' => $date,
                        'turns' => $person->birth_year !== null ? $year - $person->birth_year : null,
                    ];
                }
            }
        }
    }
}
