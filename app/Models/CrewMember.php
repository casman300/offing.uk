<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone aboard a crew (spec §7): a user (captain or crew) or a crew-owned
 * person (passenger). Leaving sets left_at; the row stays so rejoining
 * reuses it and nothing that names them loses its subject.
 */
class CrewMember extends Model
{
    public const CAPTAIN = 'captain';

    public const CREW = 'crew';

    public const PASSENGER = 'passenger';

    /** Roles an account can hold; passengers are always people. */
    public const ACCOUNT_ROLES = [self::CAPTAIN, self::CREW];

    protected $fillable = [
        'crew_id',
        'user_id',
        'person_id',
        'role',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Crew, $this>
     */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @param  Builder<CrewMember>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('left_at');
    }

    public function isCaptain(): bool
    {
        return $this->role === self::CAPTAIN;
    }

    public function isPassenger(): bool
    {
        return $this->role === self::PASSENGER;
    }

    public function displayName(): string
    {
        return $this->user->name ?? $this->person->name ?? 'Someone';
    }

    /**
     * Step off the crew. The one place membership ends, so whatever else
     * has to follow a leaver (Shipshape's rotas, spec §25) hooks in here.
     */
    public function leave(): void
    {
        $this->forceFill(['left_at' => now()])->save();
    }
}
