<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shared space (spec §7) — the household, the craft group. Things are
 * shared by being owned by a crew; every crewmate sees them. Roles live on
 * CrewMember. Deleting is soft for 30 days, then pruned.
 */
class Crew extends Model
{
    use Prunable, SoftDeletes;

    public const PRUNE_AFTER_DAYS = 30;

    protected $fillable = [
        'name',
        'created_by_user_id',
    ];

    /**
     * Everyone ever aboard, including those who've left.
     *
     * @return HasMany<CrewMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CrewMember::class);
    }

    /**
     * @return HasMany<CrewMember, $this>
     */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    /**
     * Crew-owned people: passengers, current and former.
     *
     * @return MorphMany<Person, $this>
     */
    public function people(): MorphMany
    {
        return $this->morphMany(Person::class, 'owner');
    }

    /**
     * @return HasMany<CrewInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(CrewInvite::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The signed-in user's active membership, or null if they're not aboard.
     */
    public function membershipOf(User $user): ?CrewMember
    {
        return $this->activeMembers()->where('user_id', $user->id)->first();
    }

    public function captainCount(): int
    {
        return $this->activeMembers()->whereNotNull('user_id')->where('role', CrewMember::CAPTAIN)->count();
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('deleted_at', '<=', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

    /**
     * People have no foreign key to their owner (it's a morph), so take any
     * still owned by the crew down with it.
     */
    protected function pruning(): void
    {
        $this->people()->withTrashed()->forceDelete();
    }
}
