<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Shipshape list (spec §25) — the sharing unit. Owned by a user (just
 * them) or a crew (every crewmate). Chores inherit its visibility; nothing
 * is shared per chore.
 */
class ChoreList extends Model
{
    use SoftDeletes;

    /** §7: who can edit — everyone aboard, or captains only. */
    public const EDITORS_CREW = 'crew';

    public const EDITORS_CAPTAINS = 'captains';

    protected $fillable = [
        'name',
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
     * @return HasMany<Chore, $this>
     */
    public function chores(): HasMany
    {
        return $this->hasMany(Chore::class);
    }

    public function isCrewList(): bool
    {
        return $this->owner_type === (new Crew)->getMorphClass();
    }

    /**
     * Lists owned by the user, or by a crew they're aboard.
     *
     * @param  Builder<ChoreList>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $crewIds = $user->crewMemberships()->whereHas('crew')->pluck('crew_id');

        $query->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('owner_type', $user->getMorphClass())->where('owner_id', $user->id))
            ->orWhere(fn ($q) => $q->where('owner_type', (new Crew)->getMorphClass())->whereIn('owner_id', $crewIds)));
    }

    /**
     * The viewer's membership of the owning crew; null for a private list or
     * someone not aboard.
     */
    public function membershipOf(User $user): ?CrewMember
    {
        if (! $this->isCrewList()) {
            return null;
        }

        return CrewMember::query()->active()->where('crew_id', $this->owner_id)->where('user_id', $user->id)->first();
    }

    public function viewableBy(User $user): bool
    {
        return $this->isCrewList()
            ? $this->membershipOf($user) !== null
            : (int) $this->owner_id === $user->id;
    }

    /**
     * Editing = changing chores or the list. Ticking things off is
     * responding, which every viewer can do (§7).
     */
    public function editableBy(User $user): bool
    {
        if (! $this->isCrewList()) {
            return (int) $this->owner_id === $user->id;
        }

        $me = $this->membershipOf($user);

        return $me !== null && ($this->editors === self::EDITORS_CREW || $me->isCaptain());
    }

    /**
     * Who chores on this list can be assigned to: everyone aboard the
     * crew, passengers included. A private list has nobody — it's just you.
     *
     * @return Collection<int, CrewMember>
     */
    public function assignableMembers(): Collection
    {
        if (! $this->isCrewList()) {
            return new Collection;
        }

        return CrewMember::query()->active()->where('crew_id', $this->owner_id)
            ->with(['user:id,name', 'person:id,name'])
            ->orderBy('joined_at')->orderBy('id')
            ->get();
    }

    public function ownerLabel(): string
    {
        return $this->isCrewList() ? ($this->owner->name ?? 'Crew') : 'Just me';
    }
}
