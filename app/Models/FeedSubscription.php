<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * An external ICS calendar a user subscribes to (spec §13). Personal: only
 * the subscriber sees it. Its events live on one read-only calendar of the
 * subscriber's (`calendar`), which mirrors this row's name and colour.
 *
 * The URL is a secret more often than not (Google's "secret address"), so
 * it's encrypted at rest, never sent back to the browser after saving, and
 * never logged — `host` is what's shown, like the outbound feed tokens.
 *
 * @property CarbonImmutable|null $last_fetched_at
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $next_fetch_at
 */
class FeedSubscription extends Model
{
    public const PENDING = 'pending';

    public const OK = 'ok';

    public const ERROR = 'error';

    /** Most subscriptions one person can have; each is fetched on the shared host's cron. */
    public const MAX_PER_USER = 10;

    /** How often a healthy feed is fetched. Google itself refreshes subscribed calendars a few times a day. */
    public const INTERVAL_MINUTES = 60;

    /** Back-off after failures: 15 min, 30, 1 h, 2 h … capped at a day. */
    public const BACKOFF_BASE_MINUTES = 15;

    public const BACKOFF_MAX_MINUTES = 1440;

    protected $fillable = [
        'name',
        'colour',
    ];

    protected $hidden = [
        'url',
        'etag',
        'last_hash',
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'last_fetched_at' => 'immutable_datetime',
            'last_changed_at' => 'immutable_datetime',
            'next_fetch_at' => 'immutable_datetime',
            'failures' => 'integer',
            'event_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<Calendar, $this>
     */
    public function calendar(): HasOne
    {
        return $this->hasOne(Calendar::class)->withTrashed();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Subscriptions whose next fetch is due, longest-waiting first.
     *
     * @param  Builder<FeedSubscription>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()))
            ->orderByRaw('next_fetch_at is not null')
            ->orderBy('next_fetch_at')
            ->orderBy('id');
    }

    /**
     * A new subscription and its read-only calendar.
     */
    public static function subscribe(User $user, string $url, string $host, string $name, string $colour): self
    {
        return DB::transaction(function () use ($user, $url, $host, $name, $colour) {
            $subscription = new self(['name' => $name, 'colour' => $colour]);
            $subscription->user()->associate($user);
            $subscription->forceFill(['url' => $url, 'host' => $host, 'status' => self::PENDING, 'next_fetch_at' => now()])->save();

            $calendar = new Calendar(['name' => $name, 'colour' => $colour, 'created_by_user_id' => $user->id]);
            $calendar->owner()->associate($user);
            $calendar->forceFill(['feed_subscription_id' => $subscription->id])->save();

            return $subscription;
        });
    }

    /** Rename/recolour, keeping the calendar the views show in step. */
    public function rename(string $name, string $colour): void
    {
        DB::transaction(function () use ($name, $colour) {
            $this->fill(['name' => $name, 'colour' => $colour])->save();
            $this->calendar?->forceFill(['name' => $name, 'colour' => $colour])->save();
        });
    }

    /** Unsubscribing removes every event that came from it, for good. */
    public function unsubscribe(): void
    {
        DB::transaction(function () {
            Event::query()->withTrashed()->where('feed_subscription_id', $this->id)->whereNotNull('series_id')->forceDelete();
            Event::query()->withTrashed()->where('feed_subscription_id', $this->id)->forceDelete();
            Calendar::query()->withTrashed()->where('feed_subscription_id', $this->id)->forceDelete();
            $this->delete();
        });
    }

    public function markSucceeded(bool $changed, ?string $etag, ?string $hash, ?int $eventCount = null, ?string $warning = null): void
    {
        $now = CarbonImmutable::now();

        $this->forceFill([
            'status' => self::OK,
            'failures' => 0,
            // An unchanged feed keeps the note from when it was last read.
            'last_error' => $changed ? $warning : ($this->status === self::OK ? $this->last_error : null),
            'last_fetched_at' => $now,
            'next_fetch_at' => $now->addMinutes(self::INTERVAL_MINUTES),
            'etag' => $etag ?? $this->etag,
            'last_hash' => $hash ?? $this->last_hash,
        ]);

        if ($changed) {
            $this->last_changed_at = $now;
        }

        if ($eventCount !== null) {
            $this->event_count = $eventCount;
        }

        $this->save();
    }

    /** A failed fetch: show why, and wait longer before each retry. */
    public function markFailed(string $reason): void
    {
        $failures = $this->failures + 1;
        $wait = min(self::BACKOFF_MAX_MINUTES, self::BACKOFF_BASE_MINUTES * (2 ** min($failures - 1, 10)));

        $this->forceFill([
            'status' => self::ERROR,
            'failures' => $failures,
            'last_error' => mb_substr($reason, 0, 250),
            'last_fetched_at' => CarbonImmutable::now(),
            'next_fetch_at' => CarbonImmutable::now()->addMinutes($wait),
        ])->save();
    }
}
