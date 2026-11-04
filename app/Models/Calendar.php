<?php

namespace App\Models;

use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A calendar owned by a user (groups later — spec §6). Every user gets a
 * "Personal" one on first use; see User::defaultCalendar().
 */
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    public const DEFAULT_SLUG = 'personal';

    protected $fillable = [
        'name',
        'colour',
        'slug',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
