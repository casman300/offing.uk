<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A secret, revocable ICS subscribe URL (spec §6/§8, #15).
 *
 * Only the hash is stored: the plaintext token exists once, in the response
 * that mints it. Losing the URL means revoking it and minting another.
 *
 * @property string $name
 * @property string $scope
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property string|null $last_user_agent
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
class FeedToken extends Model
{
    /** Active links per subject: enough for a phone, a laptop and a partner's Google account. */
    public const MAX_ACTIVE = 3;

    /** last_used_at is only rewritten this often, so a polling calendar app isn't a write per hit. */
    public const USAGE_RESOLUTION_MINUTES = 5;

    protected $fillable = [
        'name',
        'scope',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Mint a token for the subject. Returns the model and the plaintext,
     * which is never retrievable again.
     *
     * @return array{0: self, 1: string}
     */
    public static function mint(Model $subject, string $name, string $scope = 'full'): array
    {
        $plaintext = Str::random(40);

        $token = new self(['name' => $name, 'scope' => $scope]);
        $token->subject()->associate($subject);
        $token->token_hash = self::hash($plaintext);
        $token->save();

        return [$token, $plaintext];
    }

    public static function findActive(string $plaintext): ?self
    {
        return self::query()->active()->where('token_hash', self::hash($plaintext))->first();
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Note a fetch. Throttled to USAGE_RESOLUTION_MINUTES unless the client
     * changed, and written without touching updated_at.
     */
    public function recordUse(?string $userAgent): void
    {
        $userAgent = $userAgent === null ? null : Str::limit($userAgent, 250, '');

        $stale = $this->last_used_at === null
            || $this->last_used_at->lt(now()->subMinutes(self::USAGE_RESOLUTION_MINUTES));

        if (! $stale && $userAgent === $this->last_user_agent) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_user_agent' => $userAgent]);
        $this->timestamps = false;
        $this->save();
        $this->timestamps = true;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
