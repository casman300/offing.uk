<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MagicLink extends Model
{
    /** Signs its user in (the only kind before email changes existed). */
    public const LOGIN = 'login';

    /** Sent to a new address: proves the user owns it before it replaces theirs. */
    public const EMAIL_CONFIRM = 'email_confirm';

    /** Sent to the old address after a change: "this wasn't me" puts it back. */
    public const EMAIL_REVERT = 'email_revert';

    protected $fillable = [
        'user_id',
        'purpose',
        'email',
        'token_hash',
        'expires_at',
        'used_at',
        'remember',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'remember' => 'boolean',
        ];
    }

    /**
     * Mint a fresh link for the user and return its signed URL. Only the
     * token's hash is stored; the plain token lives in the URL alone.
     */
    public static function issue(User $user, bool $remember = true): string
    {
        $plainTextToken = Str::random(64);

        $magicLink = $user->magicLinks()->create([
            'purpose' => self::LOGIN,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addMinutes(15),
            'remember' => $remember,
        ]);

        return URL::temporarySignedRoute(
            'login.consume',
            $magicLink->expires_at,
            ['magicLink' => $magicLink->id, 'token' => $plainTextToken],
        );
    }

    /**
     * Mint an email-change link (confirm or revert) about $email, and return
     * the model with its signed URL. Never signs anyone in.
     *
     * @return array{0: self, 1: string}
     */
    public static function issueForEmail(User $user, string $purpose, string $email, int $minutes, string $route): array
    {
        $plainTextToken = Str::random(64);

        $magicLink = $user->magicLinks()->create([
            'purpose' => $purpose,
            'email' => $email,
            'token_hash' => hash('sha256', $plainTextToken),
            'expires_at' => now()->addMinutes($minutes),
            'remember' => false,
        ]);

        return [$magicLink, URL::temporarySignedRoute(
            $route,
            $magicLink->expires_at,
            ['magicLink' => $magicLink->id, 'token' => $plainTextToken],
        )];
    }

    /**
     * Whether $token is this link's, for a link of the given purpose. Says
     * nothing about expiry or use — claim() decides that.
     */
    public function matches(string $purpose, string $token): bool
    {
        return $this->purpose === $purpose && hash_equals($this->token_hash, hash('sha256', $token));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Atomically mark this link used, iff it's still unused and unexpired.
     * Returns false if a concurrent request already claimed it.
     */
    public function claim(): bool
    {
        $claimed = static::query()
            ->whereKey($this->id)
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->update(['used_at' => now()]);

        if ($claimed) {
            $this->used_at = now();
        }

        return (bool) $claimed;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
