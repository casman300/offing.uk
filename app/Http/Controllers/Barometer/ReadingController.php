<?php

namespace App\Http\Controllers\Barometer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Barometer\ReadingRequest;
use App\Models\AuditLog;
use App\Models\BarometerReading;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Barometer readings (#35). Every lookup goes through the signed-in user's
 * own relation, so someone else's reading is a 404 — never a 403 that
 * confirms the id exists. Nothing here is ever shared, even with partners.
 */
class ReadingController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $today = $user->today();

        $page = $user->barometerReadings()
            // Day first: untimed readings (null occurred_at) sort last within their day.
            ->latest('local_date')
            ->latest('occurred_at')
            ->latest('id')
            ->simplePaginate(self::PER_PAGE);

        return Inertia::render('barometer/index', [
            'today' => $today->toDateString(),
            'readings' => collect($page->items())
                ->map(fn (BarometerReading $reading) => $reading->toPageArray($timezone))
                ->values(),
            'nextPageUrl' => $page->nextPageUrl(),
            'previousPageUrl' => $page->previousPageUrl(),
            'checkIns' => $user->barometer_check_ins,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('barometer/create', $this->clock($request->user()));
    }

    public function store(ReadingRequest $request): RedirectResponse
    {
        $request->user()->barometerReadings()->create($request->readingAttributes());

        return to_route('barometer.index')->with('status', 'reading-saved');
    }

    public function edit(Request $request, int $reading): Response
    {
        $user = $request->user();

        return Inertia::render('barometer/edit', [
            'reading' => $user->barometerReadings()->findOrFail($reading)->toPageArray($user->timezone ?? User::DEFAULT_TIMEZONE),
            ...$this->clock($user),
        ]);
    }

    public function update(ReadingRequest $request, int $reading): RedirectResponse
    {
        $request->user()->barometerReadings()->findOrFail($reading)->update($request->readingAttributes());

        return to_route('barometer.index')->with('status', 'reading-saved');
    }

    /**
     * The user's local date and time now: the form's defaults and limits.
     *
     * @return array{today: string, now: string}
     */
    private function clock(User $user): array
    {
        $now = now($user->timezone ?? User::DEFAULT_TIMEZONE);

        return ['today' => $now->toDateString(), 'now' => $now->format('H:i')];
    }

    /**
     * A hard delete: health data that's deleted is gone. The audit row
     * records that it happened, never what it said.
     */
    public function destroy(Request $request, int $reading): RedirectResponse
    {
        $model = $request->user()->barometerReadings()->findOrFail($reading);
        $model->delete();

        AuditLog::record($request->user(), 'barometer.reading.deleted', $model, ['local_date' => $model->local_date], $request->ip());

        return to_route('barometer.index')->with('status', 'reading-deleted');
    }
}
