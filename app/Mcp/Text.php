<?php

namespace App\Mcp;

use Carbon\CarbonImmutable;

/**
 * The compact lines every Offing tool returns, so the briefing, `find` and
 * the write tools describe a chore or a date the same way (spec §12).
 */
final class Text
{
    /** UK style, as the server instructions ask: "Sat 25 Oct". */
    public static function date(\DateTimeInterface|string $date): string
    {
        return CarbonImmutable::parse($date)->format('D j M');
    }

    public static function days(int $days): string
    {
        return $days === 1 ? '1 day' : "{$days} days";
    }

    /**
     * "- Bins (chore 4, Home): due Tue 20 Oct, 2 days late — Sam's turn"
     *
     * @param  array<string, mixed>  $chore  a Board::chore() row
     */
    public static function chore(array $chore, string $listName): string
    {
        $due = $chore['due_on'] === null ? 'needs a date' : 'due '.self::date($chore['due_on']);
        $late = ($chore['days'] ?? 0) < 0 ? ', '.self::days(abs($chore['days'])).' late' : '';
        $who = match (true) {
            $chore['who'] === null => '',
            $chore['who']['is_me'] => ' — your turn',
            default => " — {$chore['who']['name']}'s turn",
        };

        return "- {$chore['title']} (chore {$chore['id']}, {$listName}): {$due}{$late}{$who}";
    }

    /**
     * Cap a list so one call stays small; the rest are counted, not listed.
     *
     * @param  iterable<string>  $lines
     */
    public static function capped(iterable $lines, int $max): string
    {
        $lines = collect($lines)->values();
        $more = $lines->count() > $max ? "\n…and ".($lines->count() - $max).' more — narrow the search to see them.' : '';

        return $lines->take($max)->implode("\n").$more;
    }
}
