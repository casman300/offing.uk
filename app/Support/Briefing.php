<?php

namespace App\Support;

use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\Person;
use App\Models\User;
use App\Support\Shipshape\Board;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "What's in the offing" for one user — shared by the dashboard and the
 * Connect Claude briefing (spec §12, §25) so the two can't disagree.
 * Callers check the features; this just gathers.
 */
final class Briefing
{
    /**
     * The user's own people with a birthday in the next $withinDays days, soonest first.
     *
     * @return Collection<int, array{id: int, name: string, days: int, date: ?string, turns: ?int}>
     */
    public static function birthdays(User $user, CarbonImmutable $today, int $withinDays): Collection
    {
        return $user->people()->whereNotNull('birth_day')->whereNotNull('birth_month')->get()
            ->map(fn (Person $person) => ['person' => $person, 'days' => $person->birthday()->daysUntil($today)])
            ->filter(fn ($row) => $row['days'] !== null && $row['days'] <= $withinDays)
            ->sortBy('days')
            ->map(fn ($row) => [
                'id' => $row['person']->id,
                'name' => $row['person']->name,
                'days' => $row['days'],
                'date' => $row['person']->birthday()->nextOccurrence($today)?->toDateString(),
                'turns' => $row['person']->birthday()->turns($today),
            ])
            ->values();
    }

    /**
     * Chores on the given lists in the given Board groups (overdue, today, soon…), most urgent first.
     *
     * @param  Collection<int, ChoreList>  $lists
     * @param  list<string>  $groups
     * @return Collection<int, array<string, mixed>>
     */
    public static function chores(User $user, Collection $lists, CarbonImmutable $today, array $groups): Collection
    {
        return Board::choresOn($lists)
            ->map(fn (Chore $chore) => Board::chore($chore, $user, $today))
            ->filter(fn ($chore) => in_array($chore['group'], $groups, true))
            ->sortBy([['days', 'asc'], ['title', 'asc']])
            ->values();
    }
}
