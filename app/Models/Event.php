<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A calendar event (spec §5/§6). Times are UTC with the origin `tzid`; an
 * RRULE repeats it in that zone (expanded by App\Support\Calendar\Occurrences,
 * via sabre — never by hand).
 *
 * Named like Laravel's own event system: alias it (`as CalendarEvent`) where
 * both are in scope.
 *
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property list<string>|null $exdates
 */
class Event extends Model
{
    use SoftDeletes;

    public const NORMAL = 'normal';

    public const PRIVATE = 'private';

    public const BUSY = 'busy';

    public const FREE = 'free';

    /** Made in Offing (or imported from a file — then it's ours to edit). */
    public const SOURCE_LOCAL = 'local';

    /** Comes from a subscribed feed: read-only, replaced on every sync. */
    public const SOURCE_FEED = 'feed';

    protected $fillable = [
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'tzid',
        'all_day',
        'rrule',
        'exdates',
        'visibility',
        'transparency',
        'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Event $event) {
            $event->uid ??= Str::uuid()->toString().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'offing.uk');
        });
    }

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
     * @return BelongsTo<FeedSubscription, $this>
     */
    public function feedSubscription(): BelongsTo
    {
        return $this->belongsTo(FeedSubscription::class);
    }

    public function isFromFeed(): bool
    {
        return $this->source === self::SOURCE_FEED;
    }

    /**
     * The series this event replaces one date of, for an override.
     *
     * @return BelongsTo<Event, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'series_id');
    }

    /**
     * Dates of this series that have been edited on their own.
     *
     * @return HasMany<Event, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id');
    }

    public function isOverride(): bool
    {
        return $this->series_id !== null;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::PRIVATE;
    }

    public function isRecurring(): bool
    {
        return $this->rrule !== null;
    }

    /**
     * Only the person who made a private event sees what it is (§7). Anyone
     * else on the calendar sees a detail-free "Busy" block — or nothing at
     * all if it's also marked free.
     */
    public function detailsVisibleTo(User $user): bool
    {
        return ! $this->isPrivate() || (int) $this->created_by_user_id === $user->id;
    }

    /**
     * Events that could have an occurrence overlapping [from, to): one-offs
     * that overlap it, and any series that started before it ends (sabre
     * decides whether the series still runs then).
     *
     * @param  Builder<Event>  $query
     */
    public function scopeCandidatesBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where('starts_at', '<', $to->utc())
            ->where(fn ($q) => $q->whereNotNull('rrule')->orWhere('ends_at', '>', $from->utc()))
            // An edited date of a deleted series goes with it.
            ->where(fn ($q) => $q->whereNull('series_id')->orWhereHas('series'))
            ->with('overrides:id,series_id,recurrence_id');
    }
}
