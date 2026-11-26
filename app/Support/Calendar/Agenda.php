<?php

namespace App\Support\Calendar;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Person;
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

        // Your own and crew calendars first, then subscriptions.
        return Calendar::query()->visibleTo($user)->with('owner')->orderByRaw('feed_subscription_id is not null')->orderBy('id')->get();
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
     * @param  list<int>  $editable  ids of the calendars the viewer can edit
     * @return array<string, mixed>
     */
    public static function present(Occurrence $occurrence, User $user, Collection $calendars, array $editable = []): array
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
            'override' => $event->isOverride(),
            'can_edit' => $visible && in_array($event->calendar_id, $editable, true),
            'busy_block' => ! $visible,
        ];
    }

    /** Birthdays get their own colour and a pseudo-calendar id the views can toggle. */
    public const BIRTHDAY_COLOUR = '#db2777';

    public const BIRTHDAYS = -1;

    /**
     * The user's own people's birthdays that fall in [from, to), as all-day
     * items for the views (spec §9: birthdays aren't events — they're
     * projected from people). 29 February is observed on the 28th in other
     * years, as everywhere else in the app.
     *
     * @return list<array<string, mixed>>
     */
    public static function birthdays(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = $from->startOfDay();
        $last = $to->subSecond()->startOfDay();
        $items = [];

        $people = $user->people()->whereNotNull('birth_day')->whereNotNull('birth_month')->orderBy('name')->get();

        foreach ($people as $person) {
            for ($year = $first->year; $year <= $last->year; $year++) {
                $date = $person->birthday()->observedIn($year);

                if ($date === null || $date->toDateString() < $first->toDateString() || $date->toDateString() > $last->toDateString()) {
                    continue;
                }

                $items[] = self::birthday($person, $date);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private static function birthday(Person $person, CarbonImmutable $date): array
    {
        $turns = $person->birth_year === null ? null : $date->year - $person->birth_year;

        return [
            'id' => "birthday-{$person->id}@{$date->toDateString()}",
            'event_id' => null,
            'person_id' => $person->id,
            'occurrence' => $date->toDateString(),
            'title' => "{$person->name}'s birthday 🎂".($turns !== null && $turns > 0 ? " ({$turns})" : ''),
            'location' => null,
            'all_day' => true,
            'start' => $date->toDateString(),
            'end' => $date->addDay()->toDateString(),
            'calendar_id' => self::BIRTHDAYS,
            'colour' => self::BIRTHDAY_COLOUR,
            'private' => false,
            'free' => true,
            'recurring' => false,
            'override' => false,
            'can_edit' => false,
            'busy_block' => false,
        ];
    }
}
