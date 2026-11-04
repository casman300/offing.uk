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
 *
 * With no email it's a copied link (WhatsApp and the like), for whoever
 * the captain hands it to; `name` says who. With a crew_member_id it gives
 * that passenger an account: accepting turns their row into the account's
 * membership, so their chores and history come with them.
 */
class CrewInvite extends Model
{
    public const EXPIRES_AFTER_DAYS = 7;

    /** acceptFor() results. */
    public const ACCEPTED = 'accepted';

    /** Answered, withdrawn or expired meanwhile, or the passenger is gone. */
    public const GONE = 'gone';

    /** A passenger's invite, but that account is (or was) aboard already. */
    public const ALREADY_ABOARD = 'already_aboard';

    protected $fillable = [
        'crew_id',
        'crew_member_id',
        'email',
        'name',
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

    /** A copied link rather than an emailed one: whoever opens it. */
    public function isLink(): bool
    {
        return $this->email === null;
    }

    /**
     * Accept on $user's behalf: claim the invite and bring them aboard with
     * the offered role. Someone already aboard keeps the role they have.
     *
     * A passenger's invite re-points that passenger's row at the account
     * (same row, same history) and links the crew's person to it (spec §6).
     * Merging with an account that's already been aboard is a captain's
     * job for later, so that's refused and the invite stays open.
     *
     * @return self::ACCEPTED|self::GONE|self::ALREADY_ABOARD
     */
    public function acceptFor(User $user): string
    {
        return DB::transaction(function () use ($user) {
            $existing = $this->crew->members()->where('user_id', $user->id)->first();
            $passenger = null;

            if ($this->crew_member_id !== null) {
                $passenger = $this->crew->activeMembers()->whereKey($this->crew_member_id)->where('role', CrewMember::PASSENGER)->first();

                if ($passenger === null) {
                    return self::GONE;
                }

                if ($existing !== null) {
                    return self::ALREADY_ABOARD;
                }
            }

            if (! $this->claim('accepted_at')) {
                return self::GONE;
            }

            if ($passenger !== null) {
                $person = $passenger->person;
                $passenger->forceFill(['user_id' => $user->id, 'person_id' => null, 'role' => $this->role])->save();
                $person?->forceFill(['linked_user_id' => $user->id])->save();
            } elseif ($existing === null || $existing->left_at !== null) {
                ($existing ?? $this->crew->members()->make(['user_id' => $user->id]))
                    ->fill(['role' => $this->role, 'joined_at' => now(), 'left_at' => null])
                    ->save();
            }

            return self::ACCEPTED;
        });
    }

    /**
     * The passenger this invite gives an account to, if it does.
     *
     * @return BelongsTo<CrewMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(CrewMember::class, 'crew_member_id');
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
