<?php

namespace App\Http\Controllers\Barometer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Barometer\StatsRequest;
use App\Support\Barometer\Stats;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Barometer look-back (#37): counts and averages over a date range, from
 * the same numbers the AI assistant gets (Stats). The user's own readings
 * only, and only dates and ratings — never any text. Not audited: it's the
 * owner looking at their own log, like the readings list.
 */
class StatsController extends Controller
{
    public function show(StatsRequest $request): Response
    {
        $user = $request->user();
        $today = $user->today();

        return Inertia::render('barometer/stats', [
            'stats' => Stats::for($user, $request->from(), $request->to())->toPageArray(),
            'today' => $today->toDateString(),
            'presets' => collect(['4 weeks' => 4 * 7, '12 weeks' => StatsRequest::DEFAULT_DAYS, 'A year' => Stats::MAX_DAYS - 1])
                ->map(fn (int $days, string $label) => [
                    'label' => $label,
                    'from' => $today->subDays($days - 1)->toDateString(),
                    'to' => $today->toDateString(),
                ])
                ->values(),
        ]);
    }
}
