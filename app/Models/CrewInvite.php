<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offer to join a crew (spec §7/§15): offered and accepted, never
 * imposed. For now the account with that email accepts it in-app; the
 * emailed link that doubles as signup is a later slice (token_hash).
 */
class CrewInvite extends Model
{
    public const EXPIRES_AFTER_DAYS = 7;

    protected $fillable = [
        'crew_id',
        'email',
        'role',
        'invited_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Still open: not answered, not withdrawn, not expired.
     *
     * @param  Builder<CrewInvite>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<CrewInvite>  $query
     */
    public function scopeFor(Builder $query, User $user): void
    {
        $query->where('email', strtolower($user->email));
    }
}
