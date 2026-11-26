<?php

namespace App\Support\Calendar;

use App\Models\Event;
use Carbon\CarbonImmutable;

/** One appearance of an event: the event itself for a one-off, or one date of a series. Times in UTC. */
final class Occurrence
{
    public function __construct(
        public readonly Event $event,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}
}
