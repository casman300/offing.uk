<?php

namespace App\Support\Calendar;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What a user sees on their calendars between two instants — the one place
 * the §7 private/busy rules are applied to occurrences, so the views, and
 * later the briefing and feeds, can't disagree.
 */
final class Agenda
{
    /**
     * @return EloquentCollection<int, Calendar>
     */
    public static function calendars(User $user): Collection
    {
        Calendar::ensurePersonal($user);

        return Calendar::query()->visibleTo($user)->with('owner')->orderBy('id')->get();
    }

    /**
     * @param  EloquentCollection<int, Calendar>  $calendars
     * @return Collection<int, Occurrence>
     */
    public static function occurrences(User $user, EloquentCollection $calendars, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $events = Event::query()
            ->whereIn('calendar_id', $calendars->modelKeys())
            ->candidatesBetween($from, $to)
            ->get()
            // Someone else's private + free event isn't shown at all (§7).
            ->reject(fn (Event $event) => ! $event->detailsVisibleTo($user) && $event->transparency === Event::FREE);

        return Occurrences::between($events, $from, $to);
    }

    /**
     * An occurrence for the views, in the viewer's timezone: local wall
     * times (no offset), or dates when all-day. Someone else's private event
     * is a detail-free "Busy" block.
     *
     * @param  Collection<int, Calendar>  $calendars  keyed by id
     * @return array<string, mixed>
     */
    public static function present(Occurrence $occurrence, User $user, Collection $calendars): array
    {
        $event = $occurrence->event;
        $calendar = $calendars->get($event->calendar_id);
        $tz = $user->timezone ?? User::DEFAULT_TIMEZONE;
        $visible = $event->detailsVisibleTo($user);
        $key = Occurrences::exdateKey($event, $occurrence->start);

        return [
            'id' => $event->id.'@'.$key,
            'event_id' => $visible ? $event->id : null,
            'occurrence' => $key,
            'title' => $visible ? $event->title : 'Busy',
            'location' => $visible ? $event->location : null,
            'all_day' => $event->all_day,
            'start' => $event->all_day ? $occurrence->start->toDateString() : $occurrence->start->setTimezone($tz)->format('Y-m-d\TH:i:s'),
            'end' => $event->all_day ? $occurrence->end->toDateString() : $occurrence->end->setTimezone($tz)->format('Y-m-d\TH:i:s'),
            'calendar_id' => $event->calendar_id,
            'colour' => $calendar->colour ?? Calendar::COLOURS[0],
            'private' => $event->isPrivate(),
            'free' => $event->transparency === Event::FREE,
            'recurring' => $event->isRecurring(),
            'busy_block' => ! $visible,
        ];
    }
}
