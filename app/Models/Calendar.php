<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A calendar (spec §6/§7) — the sharing unit for events. Owned by a user
 * (just them) or a crew (every crewmate). Events inherit its visibility;
 * nothing is shared per event.
 */
class Calendar extends Model
{
    use SoftDeletes;

    /** §7: who can edit — everyone aboard, or captains only. */
    public const EDITORS_CREW = 'crew';

    public const EDITORS_CAPTAINS = 'captains';

    /** The palette a calendar's colour is picked from (Tailwind 600s — readable with white text). */
    public const COLOURS = ['#2563eb', '#0d9488', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#475569'];

    protected $fillable = [
        'name',
        'colour',
        'editors',
        'created_by_user_id',
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

    public function isCrewCalendar(): bool
    {
        return $this->owner_type === (new Crew)->getMorphClass();
    }

    /**
     * Calendars owned by the user, or by a crew they're aboard.
     *
     * @param  Builder<Calendar>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $crewIds = $user->crewMemberships()->active()->whereHas('crew')->pluck('crew_id');

        $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('owner_type', $user->getMorphClass())->where('owner_id', $user->id))
            ->orWhere(fn ($q) => $q->where('owner_type', (new Crew)->getMorphClass())->whereIn('owner_id', $crewIds)));
    }

    /**
     * The viewer's membership of the owning crew; null for a private
     * calendar or someone not aboard.
     */
    public function membershipOf(User $user): ?CrewMember
    {
        if (! $this->isCrewCalendar()) {
            return null;
        }

        return CrewMember::query()->active()->where('crew_id', $this->owner_id)->where('user_id', $user->id)->first();
    }

    public function viewableBy(User $user): bool
    {
        return $this->isCrewCalendar()
            ? $this->membershipOf($user) !== null
            : (int) $this->owner_id === $user->id;
    }

    public function editableBy(User $user): bool
    {
        if (! $this->isCrewCalendar()) {
            return (int) $this->owner_id === $user->id;
        }

        $me = $this->membershipOf($user);

        return $me !== null && ($this->editors === self::EDITORS_CREW || $me->isCaptain());
    }

    /** Renaming, recolouring, the edit switch and deleting: captains only on a crew calendar. */
    public function manageableBy(User $user): bool
    {
        if (! $this->isCrewCalendar()) {
            return (int) $this->owner_id === $user->id;
        }

        return (bool) $this->membershipOf($user)?->isCaptain();
    }

    public function ownerLabel(): string
    {
        return $this->isCrewCalendar() ? ($this->owner->name ?? 'Crew') : 'Just me';
    }

    /**
     * The user's calendars, making their first one on the way in — everyone
     * has somewhere to put an event without a setup step.
     */
    public static function ensurePersonal(User $user): self
    {
        $existing = self::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->id)
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $calendar = self::make(['name' => 'My calendar', 'colour' => self::COLOURS[0], 'created_by_user_id' => $user->id]);
        $calendar->owner()->associate($user)->save();

        return $calendar;
    }
}
