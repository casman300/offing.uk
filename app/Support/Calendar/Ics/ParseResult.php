<?php

namespace App\Support\Calendar\Ics;

use Carbon\CarbonImmutable;

/** What IcsParser made of a file: the events, and what it had to skip and why. */
final class ParseResult
{
    /**
     * @param  list<ParsedEvent>  $events  every VEVENT read, cancelled ones included (flagged)
     * @param  list<string>  $errors  one line per event that couldn't be read
     */
    public function __construct(
        public readonly array $events,
        public readonly array $errors,
        public readonly bool $truncated,
        public readonly ?string $name = null,
    ) {}

    /**
     * Events to keep: not cancelled.
     *
     * @return list<ParsedEvent>
     */
    public function live(): array
    {
        return array_values(array_filter($this->events, fn (ParsedEvent $e) => ! $e->cancelled));
    }

    public function cancelledCount(): int
    {
        return count($this->events) - count($this->live());
    }

    /**
     * Earliest start and latest end of what will be imported. A series
     * counts by its first date; the preview says separately how many repeat.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    public function range(): array
    {
        $from = null;
        $to = null;

        foreach ($this->live() as $event) {
            $from = $from === null || $event->startsAt < $from ? $event->startsAt : $from;
            $to = $to === null || $event->endsAt > $to ? $event->endsAt : $to;
        }

        return ['from' => $from, 'to' => $to];
    }
}
