<?php

namespace App\Support\Calendar\Ics;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A one-off .ics import into one of your calendars (spec §13): the same
 * parser as subscriptions, no subscription row. Imported events become
 * ordinary editable events (source=local) that keep their VEVENT for
 * round-tripping, and their original UID when nothing else has it.
 *
 * Importing the same file twice adds nothing the second time: an event is
 * already there when the calendar has one with the same source UID (and
 * RECURRENCE-ID for an edited date).
 */
final class FileImport
{
    /**
     * What importing would do, without doing it.
     *
     * @return array{new: int, existing: int, cancelled: int, repeating: int, errors: list<string>, from: string|null, to: string|null, name: string|null}
     */
    public static function preview(ParseResult $parsed, Calendar $calendar): array
    {
        $plan = self::plan($parsed, $calendar);
        $range = $parsed->range();
        $live = $parsed->live();

        return [
            'new' => count($plan['create']),
            'existing' => $plan['existing'],
            'cancelled' => $parsed->cancelledCount(),
            'repeating' => count(array_filter($live, fn (ParsedEvent $e) => $e->rrule !== null)),
            'errors' => $parsed->errors,
            'from' => $range['from']?->toIso8601String(),
            'to' => $range['to']?->toIso8601String(),
            'name' => $parsed->name,
        ];
    }

    /**
     * @return array{imported: int, existing: int, first: string|null}
     */
    public static function import(ParseResult $parsed, Calendar $calendar, User $user): array
    {
        return DB::transaction(function () use ($parsed, $calendar, $user) {
            $plan = self::plan($parsed, $calendar);
            /** @var array<string, Event> $series by source UID: the calendar's own, then what this import adds */
            $series = $plan['series'];
            $cancelledDates = FeedSync::cancelledDates($parsed);
            $imported = 0;
            $first = null;

            foreach ($plan['create'] as $item) {
                $attributes = $item->attributes();
                $master = null;
                $recurrence = null;

                if ($item->isOverride()) {
                    $master = $series[$item->uid] ?? null;
                    $recurrence = $master !== null
                        ? $item->recurrenceKey($master->all_day, $master->tzid, $master->starts_at)
                        : $item->recurrenceKey($item->allDay, $item->tzid, $item->startsAt);

                    if ($master !== null && $master->overrides()->where('recurrence_id', $recurrence)->exists()) {
                        continue;
                    }
                } elseif ($item->rrule !== null && isset($cancelledDates[$item->uid])) {
                    $skipped = array_map(fn (ParsedEvent $c) => $c->recurrenceKey($item->allDay, $item->tzid, $item->startsAt), $cancelledDates[$item->uid]);
                    $all = array_values(array_unique([...$item->exdates, ...$skipped]));
                    sort($all);
                    $attributes['exdates'] = $all;
                }

                $event = new Event;
                $event->forceFill($attributes + [
                    'calendar_id' => $calendar->id,
                    'series_id' => $master?->id,
                    'recurrence_id' => $recurrence,
                    'source' => Event::SOURCE_LOCAL,
                    'created_by_user_id' => $user->id,
                ]);

                // Keep the source's UID when it's free — clients that already know the event match it up.
                if (! $item->isOverride() && ! Event::query()->withTrashed()->where('uid', $item->uid)->exists()) {
                    $event->uid = $item->uid;
                }

                $event->save();
                $imported++;
                $first = $first === null || $event->starts_at < $first ? $event->starts_at : $first;

                if (! $item->isOverride()) {
                    $series[$item->uid] = $event;
                }
            }

            return ['imported' => $imported, 'existing' => $plan['existing'], 'first' => $first?->toDateString()];
        });
    }

    /**
     * Which events are new to the calendar, in the order to create them
     * (series before their edited dates).
     *
     * @return array{create: list<ParsedEvent>, existing: int, series: array<string, Event>}
     */
    private static function plan(ParseResult $parsed, Calendar $calendar): array
    {
        $live = $parsed->live();
        $uids = array_values(array_unique(array_map(fn (ParsedEvent $e) => $e->uid, $live)));

        $have = collect();

        foreach (array_chunk($uids, 500) as $chunk) {
            $have = $have->merge(Event::query()
                ->where('calendar_id', $calendar->id)
                ->whereIn('external_uid', $chunk)
                ->get());
        }

        $series = $have->whereNull('series_id')->whereNull('recurrence_id')->keyBy('external_uid')->all();
        $create = [];
        $existing = 0;

        usort($live, fn (ParsedEvent $a, ParsedEvent $b) => $a->isOverride() <=> $b->isOverride());

        foreach ($live as $item) {
            if (! $item->isOverride()) {
                if (isset($series[$item->uid])) {
                    $existing++;

                    continue;
                }
            } else {
                $master = $series[$item->uid] ?? null;
                $key = $master !== null
                    ? $item->recurrenceKey($master->all_day, $master->tzid, $master->starts_at)
                    : $item->recurrenceKey($item->allDay, $item->tzid, $item->startsAt);

                if ($have->contains(fn (Event $e) => $e->external_uid === $item->uid && $e->recurrence_id === $key)) {
                    $existing++;

                    continue;
                }
            }

            $create[] = $item;
        }

        return ['create' => $create, 'existing' => $existing, 'series' => $series];
    }
}
