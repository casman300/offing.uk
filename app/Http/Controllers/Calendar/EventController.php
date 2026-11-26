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
use Illuminate\Support\Facades\DB;
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
        $match = $at === '' ? null : Occurrences::find($model, $at);
        $occurrence = $match === null ? null : [
            'key' => $at,
            'start' => $this->local($model, $match->start, $tz),
            'end' => $this->local($model, $match->end, $tz),
        ];
        $series = $model->isOverride() ? $model->series : null;

        return Inertia::render('calendar/event', [
            'timezone' => $tz,
            'event' => [
                'id' => $model->id,
                'title' => $model->title,
                'description' => $model->description,
                'location' => $model->location,
                'all_day' => $model->all_day,
                'start' => $this->local($model, $model->starts_at, $tz),
                'end' => $this->local($model, $model->ends_at, $tz),
                'tzid' => $model->tzid,
                'repeat' => Repeat::describe($model->rrule, $model->tzid, $model->all_day),
                'series' => $series === null ? null : [
                    'id' => $series->id,
                    'repeat' => Repeat::describe($series->rrule, $series->tzid, $series->all_day),
                ],
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

        return $this->form($user, $model, $this->values($model, $model->starts_at, $model->ends_at));
    }

    /** Edit one date of a series on its own — it becomes an override (iCal RECURRENCE-ID). */
    public function editOne(Request $request, int $event): Response
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $key = $request->string('at')->toString();
        $occurrence = Occurrences::find($model, $key);
        abort_if($occurrence === null, 404);

        return $this->form($user, $model, $this->values($model, $occurrence->start, $occurrence->end), $key);
    }

    public function updateOne(Request $request, int $event): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $key = $request->string('at')->toString();
        abort_if(Occurrences::find($model, $key) === null, 404);

        $override = new Event(['created_by_user_id' => $user->id]);
        $override->series()->associate($model);
        $override->recurrence_id = $key;
        $this->fill($override, $request, $user);

        return to_route('calendar.events.show', $override)->with('status', "Saved that one “{$override->title}”.");
    }

    /**
     * The form's fields for an event (or one date of it).
     *
     * @return array<string, mixed>
     */
    private function values(Event $model, CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        $start = $model->all_day ? $startsAt->utc() : $startsAt->setTimezone($model->tzid);
        // All-day ends are exclusive in storage, inclusive on the form.
        $end = $model->all_day ? $endsAt->utc()->subDay() : $endsAt->setTimezone($model->tzid);

        return [
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
        ];
    }

    private function local(Event $event, CarbonImmutable $at, string $tz): string
    {
        return $event->all_day ? $at->utc()->toDateString() : $at->setTimezone($tz)->format('Y-m-d\TH:i:s');
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

        DB::transaction(function () use ($model) {
            // Deleting an edited date deletes that date, not just the edit.
            if ($model->isOverride() && $model->series !== null) {
                $model->series->exdates = array_values(array_unique([...($model->series->exdates ?? []), (string) $model->recurrence_id]));
                $model->series->sequence++;
                $model->series->save();
            }
            $model->delete();
        });

        return to_route('calendar.index', ['date' => $this->localDate($model, $user)])
            ->with('eventDeleted', ['id' => $model->id, 'title' => $model->title]);
    }

    public function restore(Request $request, int $event): RedirectResponse
    {
        $model = $this->editableEvent($request->user(), $event, withTrashed: true);

        DB::transaction(function () use ($model) {
            if ($model->isOverride() && $model->series !== null) {
                $model->series->exdates = array_values(array_diff($model->series->exdates ?? [], [(string) $model->recurrence_id])) ?: null;
                $model->series->save();
            }
            $model->restore();
        });

        return back()->with('status', "“{$model->title}” is back.");
    }

    /**
     * Drag-and-drop from the views: new times arrive as wall-clock in the
     * viewer's timezone (dates, end exclusive, when all-day). A one-off or an
     * edited date just moves. A series date moves on its own (`scope=one`,
     * becoming an override) or, keeping its day, changes every date's time
     * and length (`scope=all`).
     */
    public function move(Request $request, int $event): RedirectResponse
    {
        $user = $request->user();
        $model = $this->editableEvent($user, $event);
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $data = $request->validate([
            'start' => ['required', 'string', 'max:20'],
            'end' => ['required', 'string', 'max:20'],
            'all_day' => ['required', 'boolean'],
            'occurrence' => ['nullable', 'string', 'max:20'],
            'scope' => ['nullable', Rule::in(['one', 'all'])],
        ]);

        $allDay = (bool) $data['all_day'];
        $format = $allDay ? 'Y-m-d' : 'Y-m-d\TH:i:s';
        $start = CarbonImmutable::createFromFormat("!{$format}", $data['start'], $allDay ? 'UTC' : $tz);
        $end = CarbonImmutable::createFromFormat("!{$format}", $data['end'], $allDay ? 'UTC' : $tz);

        if ($start === null || $end === null || $end <= $start) {
            throw ValidationException::withMessages(['start' => 'That isn’t a time it can move to.']);
        }

        if (! $model->isRecurring()) {
            $model->fill(['starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'all_day' => $allDay]);
            $model->sequence++;
            $model->save();

            return back()->with('status', "Moved “{$model->title}”.");
        }

        $key = (string) ($data['occurrence'] ?? '');
        $occurrence = Occurrences::find($model, $key);
        abort_if($occurrence === null, 404);

        if (($data['scope'] ?? 'one') === 'one') {
            $override = $model->replicate(['uid', 'rrule', 'exdates', 'sequence', 'series_id', 'recurrence_id', 'raw_ical']);
            $override->fill(['starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'all_day' => $allDay, 'created_by_user_id' => $user->id]);
            $override->series()->associate($model);
            $override->recurrence_id = $key;
            $override->save();

            return back()->with('status', "Moved that one “{$model->title}”.");
        }

        // Every date: same day, new time and length.
        $zone = $model->all_day ? 'UTC' : $model->tzid;
        $was = $occurrence->start->setTimezone($zone);
        $now = $start->setTimezone($zone);

        if ($allDay !== $model->all_day || $was->toDateString() !== $now->toDateString()) {
            throw ValidationException::withMessages(['start' => 'Every date can only move to a new time on the same day — open it to change the pattern.']);
        }

        $seriesStart = $model->starts_at->setTimezone($zone)->setTimeFrom($now);
        $model->starts_at = $seriesStart->utc();
        $model->ends_at = $seriesStart->add($start->diff($end))->utc();

        if (! $model->all_day) {
            // Skipped and edited dates are keyed by start time, which just changed.
            $rekey = fn (string $k) => substr($k, 0, 10).'T'.$now->format('H:i:s');
            $model->exdates = $model->exdates === null ? null : array_values(array_unique(array_map($rekey, $model->exdates)));
            $model->overrides()->get()->each(fn (Event $o) => $o->forceFill(['recurrence_id' => $rekey((string) $o->recurrence_id)])->save());
        }

        $model->sequence++;
        $model->save();

        return back()->with('status', "Moved every “{$model->title}”.");
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
     * @param  string|null  $one  the date of a series being edited on its own
     */
    private function form(User $user, ?Event $event, array $values, ?string $one = null): Response
    {
        $calendars = Agenda::calendars($user)->filter(fn (Calendar $c) => $c->editableBy($user));

        return Inertia::render('calendar/event-form', [
            'event' => $event === null ? null : [
                'id' => $event->id,
                'title' => $event->title,
                'recurring' => $event->isRecurring(),
                // One date of a series, or an already-edited date: no repeat, same calendar.
                'single' => $one !== null || $event->isOverride(),
                'one' => $one,
            ],
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

        // One date of a series stays on the series' calendar and doesn't repeat.
        $single = $event->isOverride();
        $calendar = $single ? $event->series?->calendar : Calendar::query()->find((int) $data['calendar_id']);
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

        $repeat = $single ? ['freq' => 'none'] : ($data['repeat'] ?? ['freq' => 'none']);
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

        DB::transaction(function () use ($event, $patternChanged) {
            $event->save();

            // Edited dates belong to the old pattern too.
            if ($patternChanged && $event->isRecurring()) {
                $event->overrides()->delete();
            } elseif ($event->isRecurring()) {
                $event->overrides()->update(['calendar_id' => $event->calendar_id]);
            }
        });
    }

    private function localDate(Event $event, User $user): string
    {
        return $event->all_day
            ? $event->starts_at->toDateString()
            : $event->starts_at->setTimezone($user->timezone ?? User::DEFAULT_TIMEZONE)->toDateString();
    }
}
