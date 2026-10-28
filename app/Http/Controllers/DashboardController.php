<?php

namespace App\Http\Controllers;

use App\Features\Barometer;
use App\Features\BirthdayEngine;
use App\Features\Shipshape;
use App\Models\BarometerReading;
use App\Models\ChoreList;
use App\Models\User;
use App\Support\Briefing;
use App\Support\Shipshape\Board;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * "In the offing" (spec §25): the one place everything comes together —
 * today's and overdue chores (tickable right here) and birthdays coming up,
 * a nudge for today's Barometer readings still missing their second rating,
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
            'awaitingSecondRating' => $features->active(Barometer::class) ? self::awaitingSecondRating($user, $today) : null,
        ]);
    }

    /**
     * Today's Barometer readings with no second rating yet (spec §26), as
     * id + time only: no ratings or text, so the dashboard stays safe to
     * have open in front of others, like the check-in nudge (#36).
     *
     * "Today" is the viewer's day, as on the Barometer page, so the
     * readings' own days (in their tzid) are fetched with a day either side.
     *
     * @return array<int, array{id: int, time: string|null}>
     */
    private static function awaitingSecondRating(User $user, CarbonImmutable $today): array
    {
        $timezone = $user->timezone ?? User::DEFAULT_TIMEZONE;

        return $user->barometerReadings()
            ->whereNull('rating_after')
            ->whereBetween('local_date', [$today->subDay()->toDateString(), $today->addDay()->toDateString()])
            ->orderByRaw('occurred_at is null')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (BarometerReading $reading) => ($reading->occurred_at?->setTimezone($timezone)->toDateString() ?? $reading->local_date) === $today->toDateString())
            ->map(fn (BarometerReading $reading) => ['id' => $reading->id, 'time' => $reading->occurred_at?->setTimezone($timezone)->format('H:i')])
            ->values()
            ->all();
    }
}
