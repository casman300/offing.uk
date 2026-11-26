<?php

namespace App\Http\Controllers\Calendar;

use App\Http\Controllers\Calendar\Concerns\FindsEvents;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Calendar;
use App\Models\Crew;
use App\Models\CrewMember;
use App\Support\Calendar\Agenda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your calendars, and your crews' (spec §7): owned by you or a crew. A crew
 * calendar's creation, edit-switch changes and deletion are audited —
 * they're sharing changes. Managing a crew calendar is captains only.
 */
class CalendarController extends Controller
{
    use FindsEvents;

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('calendar/calendars', [
            'colours' => Calendar::COLOURS,
            'calendars' => Agenda::calendars($user)->loadCount('events')->map(fn (Calendar $calendar) => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'colour' => $calendar->colour,
                'owner' => $calendar->ownerLabel(),
                'is_crew' => $calendar->isCrewCalendar(),
                'is_feed' => $calendar->isFeed(),
                'editors' => $calendar->editors,
                'event_count' => (int) $calendar->getAttribute('events_count'),
                'can_manage' => $calendar->manageableBy($user),
            ])->values(),
            'crews' => $user->crewMemberships()->active()->whereHas('crew')->with('crew:id,name')->get()
                ->map(fn (CrewMember $m) => ['id' => $m->crew->id, 'name' => $m->crew->name])
                ->sortBy('name')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'colour' => ['required', Rule::in(Calendar::COLOURS)],
            'owner' => ['required', 'string'],
        ]);

        $calendar = new Calendar(['name' => trim($data['name']), 'colour' => $data['colour'], 'created_by_user_id' => $user->id]);

        if ($data['owner'] === 'me') {
            $calendar->owner()->associate($user);
        } else {
            $crew = Crew::query()->findOrFail((int) $data['owner']);
            abort_if($crew->membershipOf($user) === null, 404);
            $calendar->owner()->associate($crew);
        }

        $calendar->save();

        if ($calendar->isCrewCalendar()) {
            AuditLog::record($user, 'calendar.created', $calendar, ['crew_id' => $calendar->owner_id, 'name' => $calendar->name], $request->ip());
        }

        return back()->with('status', "Added “{$calendar->name}”.");
    }

    public function update(Request $request, int $calendar): RedirectResponse
    {
        $user = $request->user();
        $model = $this->viewableCalendar($user, $calendar);
        abort_unless($model->manageableBy($user), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'colour' => ['sometimes', Rule::in(Calendar::COLOURS)],
            'editors' => ['sometimes', Rule::in([Calendar::EDITORS_CREW, Calendar::EDITORS_CAPTAINS])],
        ]);

        if (array_key_exists('editors', $data) && $data['editors'] !== $model->editors && $model->isCrewCalendar()) {
            AuditLog::record($user, 'calendar.editors_changed', $model, ['from' => $model->editors, 'to' => $data['editors']], $request->ip());
            $model->editors = $data['editors'];
        }

        if (array_key_exists('name', $data)) {
            $model->name = trim($data['name']);
        }

        if (array_key_exists('colour', $data)) {
            $model->colour = $data['colour'];
        }

        $model->save();

        return back()->with('status', 'Saved.');
    }

    public function destroy(Request $request, int $calendar): RedirectResponse
    {
        $user = $request->user();
        $model = $this->viewableCalendar($user, $calendar);
        abort_unless($model->manageableBy($user), 403);

        // Everyone keeps somewhere to put things: not your last own calendar.
        if (! $model->isCrewCalendar()) {
            $own = Calendar::query()->where('owner_type', $user->getMorphClass())->where('owner_id', $user->id)->whereNull('feed_subscription_id')->count();
            abort_if($own <= 1, 422, 'You need at least one calendar of your own.');
        }

        $model->delete();

        if ($model->isCrewCalendar()) {
            AuditLog::record($user, 'calendar.deleted', $model, ['name' => $model->name], $request->ip());
        }

        return back()->with('status', "Deleted “{$model->name}”.");
    }
}
