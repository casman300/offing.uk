<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A calendar event (spec §6). Shares a bare name with Laravel's eventing —
 * alias one of them when both are in scope.
 *
 * Times are UTC instants plus the origin IANA zone (spec §10). All-day
 * events are dates: midnight UTC of the first day to midnight UTC of the
 * day after the last, never converted into a viewer's zone.
 *
 * `visibility` (private) and `transparency` (busy/free) are independent
 * flags (spec §7) — don't fold one into the other.
 *
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    public const VISIBILITY_NORMAL = 'normal';

    public const VISIBILITY_PRIVATE = 'private';

    public const TRANSPARENCY_BUSY = 'busy';

    public const TRANSPARENCY_FREE = 'free';

    protected $fillable = [
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'tzid',
        'all_day',
        'visibility',
        'transparency',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'all_day' => 'boolean',
            'exdates' => 'array',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    public function isBusy(): bool
    {
        return $this->transparency === self::TRANSPARENCY_BUSY;
    }

    /** First day of an all-day event. */
    public function startDate(): CarbonImmutable
    {
        return $this->starts_at->utc()->startOfDay();
    }

    /** Last day of an all-day event — inclusive, unlike the stored end. */
    public function lastDate(): CarbonImmutable
    {
        return $this->ends_at->utc()->subDay()->startOfDay();
    }

    /**
     * Props for the agenda, in the viewer's timezone. Timed events also say
     * what the time was where they were made, when that reads differently.
     *
     * @return array<string, mixed>
     */
    public function toPageArray(string $viewerTz): array
    {
        $page = [
            'id' => $this->id,
            'title' => $this->title,
            'location' => $this->location,
            'description' => $this->description,
            'all_day' => $this->all_day,
            'private' => $this->isPrivate(),
            'busy' => $this->isBusy(),
            'tzid' => $this->tzid,
            'origin' => null,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];

        if ($this->all_day) {
            return [...$page,
                'start_date' => $this->startDate()->toDateString(),
                'start_time' => null,
                'end_date' => $this->lastDate()->toDateString(),
                'end_time' => null,
            ];
        }

        $start = $this->starts_at->setTimezone($viewerTz);
        $end = $this->ends_at->setTimezone($viewerTz);
        $originStart = $this->starts_at->setTimezone($this->tzid);

        if ($originStart->format('Y-m-d H:i') !== $start->format('Y-m-d H:i')) {
            $page['origin'] = ['time' => $originStart->format('H:i'), 'date' => $originStart->toDateString(), 'tzid' => $this->tzid];
        }

        return [...$page,
            'start_date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_date' => $end->toDateString(),
            'end_time' => $end->format('H:i'),
        ];
    }

    /**
     * Form values for editing, in the event's own timezone — editing an
     * event made in Paris from London still shows Paris times.
     *
     * @return array{title: string, location: string, description: string, all_day: bool, tzid: string, start_date: string, start_time: string, end_date: string, end_time: string, private: bool, busy: bool}
     */
    public function toFormArray(): array
    {
        $start = $this->all_day ? $this->startDate() : $this->starts_at->setTimezone($this->tzid);
        $end = $this->all_day ? $this->lastDate() : $this->ends_at->setTimezone($this->tzid);

        return [
            'title' => $this->title,
            'location' => $this->location ?? '',
            'description' => $this->description ?? '',
            'all_day' => $this->all_day,
            'tzid' => $this->tzid,
            'start_date' => $start->toDateString(),
            'start_time' => $this->all_day ? '' : $start->format('H:i'),
            'end_date' => $end->toDateString(),
            'end_time' => $this->all_day ? '' : $end->format('H:i'),
            'private' => $this->isPrivate(),
            'busy' => $this->isBusy(),
        ];
    }
}
