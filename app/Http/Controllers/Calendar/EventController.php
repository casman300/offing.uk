<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Calendar\Concerns\FindsEvents;
use App\Http\Controllers\Controller;
use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use App\Support\Calendar\Agenda;
use App\Support\Calendar\Occurrences;
use App\Support\Calendar\Repeat;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Creating, editing and deleting events (spec §5, Phase 1b). A repeating
 * event is edited as a whole series; a single date can be dropped from it
 * ("just this one"), which adds an EXDATE. Deleting is soft, with undo.
 */
class EventController extends Controller
{
    use FindsEvents;

    public function show(Request $request, int $event): Response
    {
        $user = $request->user();
        $model = $this->viewableEvent($user, $event);
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;

        // Which date of a series was clicked, if it's really one of its dates.
        $at = $request->string('at')->toString();
        $occurrence = null;

        if ($at !== '' && $model->isRecurring()) {
            $start = $model->all_day ? CarbonImmutable::parse($at, 'UTC') : CarbonImmutable::parse($at, $model->tzid);
            $match = collect(Occurrences::of($model, $start->subMinute(), $start->addMinute()))
                ->first(fn ($o) => Occurrences::exdateKey($model, $o->start) === $at);
            $occurrence = $match === null ? null : [
                'key' => $at,
                'start' => $model->all_day ? $match->start->toDateString() : $match->start->setTimezone($tz)->format('Y-m-d\TH:i:s'),
                'end' => $model->all_day ? $match->end->toDateString() : $match->end->setTimezone($tz)->format('Y-m-d\TH:i:s'),
            ];
        }

        return Inertia::render('calendar/event', [
            'timezone' => $tz,
            'event' => [
                'id' => $model->id,
                'title' => $model->title,
                'description' => $model->description,
                'location' => $model->location,
                'all_day' => $model->all_day,
                'start' => $model->all_day ? $model->starts_at->toDateString() : $model->starts_at->setTimezone($tz)->format('Y-m-d\TH:i:s'),
                'end' => $model->all_day ? $model->ends_at->toDateString() : $model->ends_at->setTimezone($tz)->format('Y-m-d\TH:i:s'),
                'tzid' => $model->tzid,
                'repeat' => Repeat::describe($model->rrule, $model->tzid, $model->all_day),
                'private' => $model->isPrivate(),
                'free' => $model->transparency === Event::FREE,
                'calendar' => ['id' => $model->calendar->id, 'name' => $model->calendar->name, 'colour' => $model->calendar->colour, 'owner' => $model->calendar->ownerLabel()],
                'can_edit' => $model->calendar->editableBy($user),
            ],
            'occurrence' => $occurrence,
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $now = CarbonImmutable::now($tz);

        $date = $request->string('date')->toString();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $now->toDateString();
        $time = $request->string('time')->toString();
        // Default to the next whole hour, an hour long.
        $start = preg_match('/^\d{2}:\d{2}$/', $time) === 1
            ? CarbonImmutable::parse("{$date} {$time}", $tz)
            : CarbonImmutable::parse($date.' '.$now->addHour()->format('H:00'), $tz);
        $end = $start->addHour();

        return $this->form($user, null, [
            'calendar_id' => $request->integer('calendar') ?: null,
            'title' => '',
            'all_day' => $request->boolean('all_day'),
            'start_date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_date' => $end->toDateString(),
            'end_time' => $end->format('H:i'),
            'tzid' => $tz,
            'location' => '',
            'description' => '',
            'private' => false,
            'free' => false,
            'repeat' => Repeat::fields(null, $tz, false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $event = new Event(['created_by_user_id' => $user->id]);
        $this->fill($event, $request, $user);

        return to_route('calendar.index', ['date' => $this->localDate($event, $user)])->with('status', "Added “{$event->title}”.");
    }

    public function edit(Request $request, int $event): Response
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $start = $model->all_day ? $model->starts_at->utc() : $model->starts_at->setTimezone($model->tzid);
        // All-day ends are exclusive in storage, inclusive on the form.
        $end = $model->all_day ? $model->ends_at->utc()->subDay() : $model->ends_at->setTimezone($model->tzid);

        return $this->form($user, $model, [
            'calendar_id' => $model->calendar_id,
            'title' => $model->title,
            'all_day' => $model->all_day,
            'start_date' => $start->toDateString(),
            'start_time' => $model->all_day ? '09:00' : $start->format('H:i'),
            'end_date' => $end->toDateString(),
            'end_time' => $model->all_day ? '10:00' : $end->format('H:i'),
            'tzid' => $model->tzid,
            'location' => $model->location ?? '',
            'description' => $model->description ?? '',
            'private' => $model->isPrivate(),
            'free' => $model->transparency === Event::FREE,
            'repeat' => Repeat::fields($model->rrule, $model->tzid, $model->all_day),
        ]);
    }

    public function update(Request $request, int $event): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $this->fill($model, $request, $user);

        return to_route('calendar.events.show', $model)->with('status', "Saved “{$model->title}”.");
    }

    public function destroy(Request $request, int $event): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $model->delete();

        return to_route('calendar.index', ['date' => $this->localDate($model, $user)])
            ->with('eventDeleted', ['id' => $model->id, 'title' => $model->title]);
    }

    public function restore(Request $request, int $event): RedirectResponse
    {
        $model = $this->editableEvent($request->user(), $event, withTrashed: true);
        $model->restore();

        return back()->with('status', "“{$model->title}” is back.");
    }

    /** Drop one date from a series — "delete just this one". */
    public function skip(Request $request, int $event): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $key = $request->validate(['occurrence' => ['required', 'string', 'max:20']])['occurrence'];
        abort_unless($model->isRecurring(), 422);

        $model->exdates = array_values(array_unique([...($model->exdates ?? []), $key]));
        $model->sequence++;
        $model->save();

        return to_route('calendar.index', ['date' => substr($key, 0, 10)])->with('status', "Removed that one “{$model->title}”.");
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function form(User $user, ?Event $event, array $values): Response
    {
        $calendars = Agenda::calendars($user)->filter(fn (Calendar $c) => $c->editableBy($user));

        return Inertia::render('calendar/event-form', [
            'event' => $event === null ? null : ['id' => $event->id, 'title' => $event->title, 'recurring' => $event->isRecurring()],
            'values' => $values + ['calendar_id' => null],
            'calendars' => $calendars->map(fn (Calendar $c) => ['id' => $c->id, 'name' => $c->name, 'colour' => $c->colour, 'owner' => $c->ownerLabel()])->values(),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    private function fill(Event $event, Request $request, User $user): void
    {
        $data = $request->validate([
            'calendar_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'all_day' => ['boolean'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'required_unless:all_day,true', 'date_format:H:i'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'end_time' => ['nullable', 'required_unless:all_day,true', 'date_format:H:i'],
            'tzid' => ['required', 'timezone:all'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'private' => ['boolean'],
            'free' => ['boolean'],
            'repeat' => ['array'],
            'repeat.freq' => ['required', Rule::in(['none', 'custom', ...array_keys(Repeat::FREQS)])],
            'repeat.interval' => ['nullable', 'integer', 'between:1,99'],
            'repeat.days' => ['nullable', 'array'],
            'repeat.days.*' => [Rule::in(Repeat::DAYS)],
            'repeat.ends' => ['nullable', Rule::in(['never', 'count', 'until'])],
            'repeat.count' => ['nullable', 'integer', 'between:1,999', 'required_if:repeat.ends,count'],
            'repeat.until' => ['nullable', 'date_format:Y-m-d', 'required_if:repeat.ends,until'],
        ], [
            'repeat.count.required_if' => 'How many times?',
            'repeat.until.required_if' => 'Until when?',
        ]);

        $calendar = Calendar::query()->find((int) $data['calendar_id']);
        if ($calendar === null || ! $calendar->viewableBy($user) || ! $calendar->editableBy($user)) {
            throw ValidationException::withMessages(['calendar_id' => 'Pick one of your calendars.']);
        }

        $allDay = (bool) ($data['all_day'] ?? false);
        $tzid = $data['tzid'];

        if ($allDay) {
            $start = CarbonImmutable::parse($data['start_date'], 'UTC');
            $end = CarbonImmutable::parse($data['end_date'], 'UTC')->addDay();
        } else {
            $start = CarbonImmutable::parse($data['start_date'].' '.$data['start_time'], $tzid);
            $end = CarbonImmutable::parse($data['end_date'].' '.$data['end_time'], $tzid);
        }

        if ($end <= $start) {
            throw ValidationException::withMessages(['end_date' => 'It needs to end after it starts.']);
        }

        $repeat = $data['repeat'] ?? ['freq' => 'none'];
        if ($repeat['freq'] === 'custom') {
            // An existing pattern the form can't show — keep it as it was.
            $rrule = $event->rrule;
        } else {
            $rrule = Repeat::rrule($repeat, $start, $allDay, $tzid);
        }

        if ($rrule !== null && ($repeat['ends'] ?? 'never') === 'until' && ($repeat['until'] ?? '') < $data['start_date']) {
            throw ValidationException::withMessages(['repeat.until' => 'The last date can’t be before it starts.']);
        }

        // Skipped dates belong to the old pattern; a new start or pattern drops them.
        $patternChanged = ! $event->exists || $rrule !== $event->rrule || ! $start->equalTo($event->starts_at) || $allDay !== $event->all_day || $tzid !== $event->tzid;

        $event->fill([
            'title' => trim($data['title']),
            'description' => filled($data['description'] ?? null) ? $data['description'] : null,
            'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'tzid' => $tzid,
            'all_day' => $allDay,
            'rrule' => $rrule,
            'exdates' => $patternChanged ? null : $event->exdates,
            'visibility' => ($data['private'] ?? false) ? Event::PRIVATE : Event::NORMAL,
            'transparency' => ($data['free'] ?? false) ? Event::FREE : Event::BUSY,
        ]);
        $event->calendar()->associate($calendar);

        if ($event->exists) {
            $event->sequence++;
        }

        $event->save();
    }

    private function localDate(Event $event, User $user): string
    {
        return $event->all_day
            ? $event->starts_at->toDateString()
            : $event->starts_at->setTimezone($user->timezone ?? User::DEFAULT_TIMEZONE)->toDateString();
    }
}
