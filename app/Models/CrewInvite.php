<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * An offer to join a crew (spec §7/§15): offered and accepted, never
 * imposed. The account with that email can answer it in-app, and the
 * emailed link (token_hash) doubles as signup for someone with no account.
 * Like a magic link, only the token's hash is stored and answering is an
 * atomic claim, so a link can never be used twice.
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

    protected $hidden = ['token_hash'];

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
     * Mint this invite's emailed link and return its signed URL. Only the
     * hash is kept; minting again replaces the token, so older links die.
     */
    public function issueLink(): string
    {
        $plainTextToken = Str::random(64);
        $this->forceFill(['token_hash' => hash('sha256', $plainTextToken)])->save();

        return URL::temporarySignedRoute(
            'crew.join',
            $this->expires_at,
            ['invite' => $this->id, 'token' => $plainTextToken],
        );
    }

    /**
     * Whether $token is this invite's link. Says nothing about whether it's
     * still open — isPending() and claim() decide that.
     */
    public function matches(string $token): bool
    {
        return $this->token_hash !== null && hash_equals($this->token_hash, hash('sha256', $token));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->declined_at === null
            && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Atomically answer the invite ('accepted_at' or 'declined_at'), iff it's
     * still pending. Returns false if it was answered, withdrawn or expired
     * in the meantime — including by a concurrent request.
     */
    public function claim(string $answer): bool
    {
        $claimed = static::query()->whereKey($this->id)->pending()->update([$answer => now()]);

        if ($claimed) {
            $this->setAttribute($answer, now());
        }

        return (bool) $claimed;
    }

    /**
     * Accept on $user's behalf: claim the invite and bring them aboard with
     * the offered role. Someone already aboard keeps the role they have.
     */
    public function acceptFor(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            if (! $this->claim('accepted_at')) {
                return false;
            }

            $membership = $this->crew->members()->firstOrNew(['user_id' => $user->id]);

            if (! $membership->exists || $membership->left_at !== null) {
                $membership->fill(['role' => $this->role, 'joined_at' => now(), 'left_at' => null])->save();
            }

            return true;
        });
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
        // An invite goes to an inbox, so only a verified address may claim it (H1).
        if ($user->email_verified_at === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('email', strtolower($user->email));
    }
}
