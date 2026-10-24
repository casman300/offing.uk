<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BarometerReadingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Barometer reading (#35): a row of the CBT anxiety-tracking form.
 * Owner-only health data — see the migration and spec §26.
 *
 * @property CarbonImmutable|null $occurred_at null = time not recorded
 * @property string $local_date Y-m-d. Deliberately not a date cast: that
 *                              stores a time too on SQLite, which breaks
 *                              range queries against plain dates.
 */
class BarometerReading extends Model
{
    /** @use HasFactory<BarometerReadingFactory> */
    use HasFactory;

    public const MIN_RATING = 0;

    public const MAX_RATING = 10;

    public const MAX_TEXT = 5000;

    /**
     * Every header after the time, in the paper form's order, with the
     * labels used on screen and in exports.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'situation' => 'Situation',
        'thoughts' => 'Thoughts',
        'sensations' => 'Physical sensations',
        'rating_before' => 'Rating',
        'coping_action' => 'What did I do?',
        'self_talk' => 'What did I say to myself?',
        'rating_after' => 'Second rating',
    ];

    /** Free-text columns: encrypted at rest, optional. */
    public const TEXT_COLUMNS = ['situation', 'thoughts', 'sensations', 'coping_action', 'self_talk'];

    /** Columns an export may leave out. The first rating always stays. */
    public const OMITTABLE_COLUMNS = [...self::TEXT_COLUMNS, 'rating_after'];

    protected $fillable = [
        'occurred_at',
        'local_date',
        'tzid',
        'situation',
        'thoughts',
        'sensations',
        'rating_before',
        'coping_action',
        'self_talk',
        'rating_after',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'rating_before' => 'integer',
            'rating_after' => 'integer',
            'situation' => 'encrypted',
            'thoughts' => 'encrypted',
            'sensations' => 'encrypted',
            'coping_action' => 'encrypted',
            'self_talk' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // With a time, the day follows from it; without one, the day was given directly.
        static::saving(function (BarometerReading $reading) {
            if ($reading->occurred_at !== null) {
                $reading->local_date = $reading->occurred_at->setTimezone($reading->tzid)->toDateString();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Props for the Barometer pages. Times render in the viewer's
     * timezone (CLAUDE.md: store UTC + origin TZID, render for the viewer).
     *
     * @return array<string, mixed>
     */
    public function toPageArray(string $timezone): array
    {
        $local = $this->occurred_at?->setTimezone($timezone);

        return [
            'id' => $this->id,
            'date' => $local?->toDateString() ?? $this->local_date,
            'time' => $local?->format('H:i'),
            'situation' => $this->situation,
            'thoughts' => $this->thoughts,
            'sensations' => $this->sensations,
            'rating_before' => $this->rating_before,
            'coping_action' => $this->coping_action,
            'self_talk' => $this->self_talk,
            'rating_after' => $this->rating_after,
        ];
    }
}
