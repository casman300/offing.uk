<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MagicLink extends Model
{
    protected $fillable = [
        'user_id',
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
