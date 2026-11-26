<?php

namespace App\Support\Calendar\Ics;

use Carbon\CarbonImmutable;

/**
 * One VEVENT, read into Offing's event shape by IcsParser. Times are UTC
 * instants; `exdates` are already keyed like `events.exdates`. An override
 * (RECURRENCE-ID) keeps the replaced date as an instant, because its key
 * depends on the series' own timezone, which may not be its own.
 */
final class ParsedEvent
{
    /**
     * @param  list<string>  $exdates
     */
    public function __construct(
        public readonly string $uid,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly string $tzid,
        public readonly bool $allDay,
        public readonly ?string $rrule,
        public readonly array $exdates,
        public readonly bool $private,
        public readonly bool $free,
        public readonly int $sequence,
        public readonly bool $cancelled,
        public readonly string $raw,
        public readonly ?CarbonImmutable $recurrenceAt = null,
        public readonly bool $recurrenceIsDate = false,
    ) {}

    public function isOverride(): bool
    {
        return $this->recurrenceAt !== null;
    }

    /**
     * The key of the date this override replaces, in the series' terms:
     * its date for an all-day series, else its wall time in the series'
     * zone (a date-only RECURRENCE-ID on a timed series takes the series'
     * own time of day).
     */
    public function recurrenceKey(bool $seriesAllDay, string $seriesTzid, CarbonImmutable $seriesStart): string
    {
        $at = $this->recurrenceAt ?? $this->startsAt;

        if ($seriesAllDay) {
            return $this->recurrenceIsDate ? $at->utc()->format('Y-m-d') : $at->setTimezone($seriesTzid)->format('Y-m-d');
        }

        if ($this->recurrenceIsDate) {
            return $at->utc()->format('Y-m-d').'T'.$seriesStart->setTimezone($seriesTzid)->format('H:i:s');
        }

        return $at->setTimezone($seriesTzid)->format('Y-m-d\TH:i:s');
    }

    /** Identity within one source: UID, plus RECURRENCE-ID for an override. */
    public function sourceKey(): string
    {
        return $this->uid.'|'.($this->recurrenceAt?->utc()->format('Ymd\THis') ?? '');
    }

    /**
     * The model attributes everything but the series link depends on.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'tzid' => $this->tzid,
            'all_day' => $this->allDay,
            'rrule' => $this->rrule,
            'exdates' => $this->exdates === [] ? null : $this->exdates,
            'visibility' => $this->private ? 'private' : 'normal',
            'transparency' => $this->free ? 'free' : 'busy',
            'sequence' => $this->sequence,
            'raw_ical' => $this->raw,
            'external_uid' => $this->uid,
        ];
    }
}
