<?php

namespace App\Support\Reminders;

use App\Models\Person;
use Carbon\CarbonImmutable;

/**
 * The push notification for a batch of reminders, e.g. title
 * "Tomorrow: Auntie Sue turns 43", or "Tomorrow: Auntie Sue and 2 others"
 * with everyone listed in the body. Never includes notes.
 */
final class ReminderPayload
{
    /**
     * @param  list<array{person: Person, date: CarbonImmutable, turns: ?int}>  $entries
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function push(array $entries, int $daysBefore): array
    {
        $when = match ($daysBefore) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => "In {$daysBefore} days",
        };

        $describe = fn (array $e) => $e['turns'] !== null ? "{$e['person']->name} turns {$e['turns']}" : "{$e['person']->name}’s birthday";
        $first = $entries[0];
        $others = count($entries) - 1;

        $title = $others === 0
            ? "{$when}: {$describe($first)}"
            : "{$when}: {$first['person']->name} and {$others} ".($others === 1 ? 'other' : 'others');

        $body = $others === 0
            ? $first['date']->format('l j F')
            : implode(', ', array_map(fn (array $e) => $e['turns'] !== null ? "{$e['person']->name} ({$e['turns']})" : $e['person']->name, $entries));

        return [
            'title' => $title,
            'body' => $body,
            'url' => $others === 0 ? route('people.show', $first['person']->id, false) : route('people.index', [], false),
            // Same tag → a re-delivered reminder replaces rather than stacks.
            'tag' => 'reminder-'.$first['date']->toDateString().'-'.$daysBefore,
        ];
    }
}
