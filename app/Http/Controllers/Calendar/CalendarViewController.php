<?php

namespace App\Http\Controllers\Calendar;

use App\Features\BirthdayEngine;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\User;
use App\Support\Calendar\Agenda;
use App\Support\Calendar\Occurrence;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * The month/week/agenda views (spec §23 Phase 1b). The page renders once;
 * the calendar component asks `occurrences` for each range it shows.
 *
 * Everything the browser sees is wall-clock time in the viewer's profile
 * timezone (§10) with no offset, so the view never depends on the device's
 * own zone — a phone abroad still shows home time until the profile says
 * otherwise.
 */
class CalendarViewController extends Controller
{
    /** No view spans more than this; a longer request is a mistake or abuse. */
    private const MAX_RANGE_DAYS = 62;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;

        return Inertia::render('calendar/index', [
            'timezone' => $tz,
            'now' => CarbonImmutable::now($tz)->format('Y-m-d\TH:i:s'),
            'birthdays' => Feature::for($user)->active(BirthdayEngine::class),
            'calendars' => Agenda::calendars($user)->map(fn (Calendar $calendar) => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'colour' => $calendar->colour,
                'owner' => $calendar->ownerLabel(),
                'is_crew' => $calendar->isCrewCalendar(),
                'is_feed' => $calendar->isFeed(),
                'can_edit' => $calendar->editableBy($user),
            ])->values(),
        ]);
    }

    public function occurrences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'string', 'max:40'],
            'end' => ['required', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $from = self::wallClock($data['start'], $tz);
        $to = self::wallClock($data['end'], $tz);

        abort_if($from === null || $to === null || $to <= $from || $from->diffInDays($to) > self::MAX_RANGE_DAYS, 422, 'Bad range.');

        $calendars = Agenda::calendars($user)->keyBy('id');
        /** @var list<int> $editable */
        $editable = $calendars->filter(fn (Calendar $c) => $c->editableBy($user))->keys()->map(fn ($id) => (int) $id)->values()->all();

        $items = Agenda::occurrences($user, $calendars, $from, $to)
            ->map(fn (Occurrence $o) => Agenda::present($o, $user, $calendars, $editable))
            ->values()
            ->all();

        if (Feature::for($user)->active(BirthdayEngine::class)) {
            array_unshift($items, ...Agenda::birthdays($user, $from, $to));
        }

        return response()->json($items);
    }

    /**
     * The calendar component runs in "UTC" so it never shifts times; the
     * date-time it sends is really a wall-clock time in the viewer's zone.
     * Keep the digits, drop any offset.
     */
    private static function wallClock(string $value, string $tz): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}(?::\d{2})?))?/', $value, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($m[1].' '.($m[2] ?? '00:00'), $tz);
    }
}
