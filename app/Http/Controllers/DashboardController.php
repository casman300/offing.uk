<?php

namespace App\Http\Controllers;

use App\Features\BirthdayEngine;
use App\Features\Shipshape;
use App\Models\Chore;
use App\Models\ChoreList;
use App\Models\Person;
use App\Support\Shipshape\Board;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * "In the offing" (spec §25): the one place everything comes together —
 * today's and overdue chores (tickable right here) and birthdays coming up,
 * later events too. Each part only appears if its feature is on for you.
 */
class DashboardController extends Controller
{
    public const BIRTHDAY_DAYS = 14;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = $user->today();
        $features = Feature::for($user);

        $chores = null;
        $lists = collect();

        if ($features->active(Shipshape::class)) {
            $lists = Board::visibleLists($user);
            $chores = Board::choresOn($lists)
                ->map(fn (Chore $chore) => Board::chore($chore, $user, $today))
                ->filter(fn ($chore) => in_array($chore['group'], ['overdue', 'today'], true))
                ->sortBy([['days', 'asc'], ['title', 'asc']])
                ->values();
        }

        $birthdays = null;

        if ($features->active(BirthdayEngine::class)) {
            $birthdays = $user->people()->whereNotNull('birth_day')->whereNotNull('birth_month')->get()
                ->map(fn (Person $person) => ['person' => $person, 'days' => $person->birthday()->daysUntil($today)])
                ->filter(fn ($row) => $row['days'] !== null && $row['days'] <= self::BIRTHDAY_DAYS)
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

        return Inertia::render('dashboard', [
            'today' => $today->toDateString(),
            'chores' => $chores,
            'lists' => $lists->map(fn (ChoreList $list) => Board::listSummary($list, $user))->values(),
            'birthdays' => $birthdays,
        ]);
    }
}
