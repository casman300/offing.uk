<?php

namespace App\Http\Controllers\Calendar\Concerns;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;

/**
 * A calendar or event you can't see is a 404, never a 403 that confirms it
 * exists — and so is someone else's private event (§7). Editing
 * additionally needs the calendar's edit switch to allow you.
 */
trait FindsEvents
{
    protected function viewableCalendar(User $user, int $id): Calendar
    {
        $calendar = Calendar::query()->findOrFail($id);
        abort_unless($calendar->viewableBy($user), 404);

        return $calendar;
    }

    protected function editableCalendar(User $user, int $id): Calendar
    {
        $calendar = $this->viewableCalendar($user, $id);
        abort_unless($calendar->editableBy($user), 403);

        return $calendar;
    }

    protected function viewableEvent(User $user, int $id, bool $withTrashed = false): Event
    {
        $event = Event::query()->when($withTrashed, fn ($q) => $q->withTrashed())->with('calendar')->findOrFail($id);
        abort_unless($event->calendar !== null && $event->calendar->viewableBy($user) && $event->detailsVisibleTo($user), 404);

        return $event;
    }

    protected function editableEvent(User $user, int $id, bool $withTrashed = false): Event
    {
        $event = $this->viewableEvent($user, $id, $withTrashed);
        abort_unless($event->calendar->editableBy($user), 403);

        return $event;
    }
}
