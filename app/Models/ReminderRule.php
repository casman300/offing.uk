<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder rule (spec §6, #18). For now each user has two fixed slots;
 * `offset_def` holds {days_before, time} so Phase 2's editor can add rules
 * without a migration.
 *
 * @property array{days_before: int, time: string} $offset_def
 */
class ReminderRule extends Model
{
    public const DAY_BEFORE = 'day_before';

    public const ON_THE_DAY = 'on_the_day';

    /**
     * Defaults for the two slots (spec §9: 20:00 the day before, 08:00 on the day).
     *
     * @var array<string, array{days_before: int, time: string}>
     */
    public const SLOTS = [
        self::DAY_BEFORE => ['days_before' => 1, 'time' => '20:00'],
        self::ON_THE_DAY => ['days_before' => 0, 'time' => '08:00'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'offset_def' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function daysBefore(): int
    {
        return (int) ($this->offset_def['days_before'] ?? 0);
    }

    /** "HH:MM" in the user's timezone. */
    public function time(): string
    {
        return (string) ($this->offset_def['time'] ?? '08:00');
    }
}
