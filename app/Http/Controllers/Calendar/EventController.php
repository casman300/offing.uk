<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calendar\EventRequest;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar, first slice: one-off and all-day events on the user's own
 * "Personal" calendar, with an agenda view. Every lookup goes through
 * `$user->events()`, so someone else's event is a 404, never a 403.
 */
class EventController extends Controller
{
    /** Agenda length — enough for a long scroll, bounded for big calendars. */
    private const AGENDA_LIMIT = 200;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $tz = self::timezone($user);
        $today = $user->today();

        // Anything still running: timed events by the instant, all-day ones
        // by today's date (stored as midnight UTC, so compare like with like).
        $now = CarbonImmutable::now('UTC');
        $todayAsUtcDate = CarbonImmutable::createFromFormat('!Y-m-d', $today->toDateString(), 'UTC');

        $events = $user->events()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('all_day', false)->where('ends_at', '>', $now))
                ->orWhere(fn ($q) => $q->where('all_day', true)->where('ends_at', '>', $todayAsUtcDate)))
            ->orderBy('starts_at')
            ->orderBy('events.id')
            ->limit(self::AGENDA_LIMIT)
            ->get()
            ->map(fn (Event $event) => $event->toPageArray($tz))
            // All-day events are stored at UTC midnight, which can sort them
            // after a timed event the same local morning. Sort by local day,
            // all-day first, then time.
            ->sortBy(fn (array $e) => [$e['start_date'], $e['all_day'] ? 0 : 1, $e['start_time'] ?? ''])
            ->values();

        return Inertia::render('calendar/index', [
            'today' => $today->toDateString(),
            'timezone' => $tz,
            'events' => $events,
            'recentlyDeleted' => $user->events()->onlyTrashed()
                ->latest('events.deleted_at')
                ->limit(20)
                ->get()
                ->map(fn (Event $event) => $event->toPageArray($tz))
                ->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('calendar/create', [
            'today' => $user->today()->toDateString(),
            'timezone' => self::timezone($user),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(EventRequest $request): RedirectResponse
    {
        $user = $request->user();

        $event = new Event($request->eventAttributes());
        $event->uid = Str::uuid()->toString().'@'.self::uidHost();
        $event->created_by = $user->id;
        $user->defaultCalendar()->events()->save($event);

        return to_route('calendar.index')->with('status', self::isOver($event, $user) ? 'event-saved-past' : 'event-created');
    }

    public function edit(Request $request, int $event): Response
    {
        $user = $request->user();
        $model = $user->events()->findOrFail($event);

        return Inertia::render('calendar/edit', [
            'event' => ['id' => $model->id, ...$model->toFormArray()],
            'timezone' => self::timezone($user),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(EventRequest $request, int $event): RedirectResponse
    {
        $model = $request->user()->events()->findOrFail($event);
        $model->fill($request->eventAttributes());

        if ($model->isDirty()) {
            // Calendar apps only take a changed event when SEQUENCE goes up.
            $model->sequence++;
            $model->save();
        }

        return to_route('calendar.index')->with('status', self::isOver($model, $request->user()) ? 'event-saved-past' : 'event-updated');
    }

    public function destroy(Request $request, int $event): RedirectResponse
    {
        $model = $request->user()->events()->findOrFail($event);
        $model->delete();

        AuditLog::record($request->user(), 'event.deleted', $model, ['title' => $model->title], $request->ip());

        return to_route('calendar.index')->with('deleted', ['id' => $model->id, 'name' => $model->title]);
    }

    public function restore(Request $request, int $event): RedirectResponse
    {
        $model = $request->user()->events()->onlyTrashed()->findOrFail($event);
        $model->restore();

        AuditLog::record($request->user(), 'event.restored', $model, ['title' => $model->title], $request->ip());

        return to_route('calendar.index')->with('status', 'event-restored');
    }

    /** Already finished, so the agenda won't show it — the flash says so. */
    private static function isOver(Event $event, User $user): bool
    {
        return $event->all_day
            ? $event->lastDate()->toDateString() < $user->today()->toDateString()
            : $event->ends_at->lte(CarbonImmutable::now('UTC'));
    }

    private static function timezone(User $user): string
    {
        return $user->timezone ?? User::DEFAULT_TIMEZONE;
    }

    private static function uidHost(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'offing.uk';
    }
}
