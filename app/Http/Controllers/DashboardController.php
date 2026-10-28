<?php

namespace App\Http\Controllers;

use App\Features\BirthdayEngine;
use App\Features\Crews;
use App\Features\Shipshape;
use App\Models\ChoreList;
use App\Support\Briefing;
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
            $chores = Briefing::chores($user, $lists, $today, ['overdue', 'today']);
        }

        $birthdays = $features->active(BirthdayEngine::class)
            ? Briefing::birthdays($user, $today, self::BIRTHDAY_DAYS)
            : null;

        return Inertia::render('dashboard', [
            'today' => $today->toDateString(),
            'chores' => $chores,
            'lists' => $lists->map(fn (ChoreList $list) => Board::listSummary($list, $user))->values(),
            'birthdays' => $birthdays,
            // Spec §15: aboard a crew (say, via an emailed invite) while Crews
            // is still in preview for them — say why, rather than show nothing.
            'crewsInPreview' => $features->active(Crews::class) ? [] : $user->crewMemberships()->whereHas('crew')->with('crew:id,name')->get()
                ->map(fn ($membership) => $membership->crew->name)->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
        ]);
    }
}
