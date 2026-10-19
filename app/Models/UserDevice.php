<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser/device that allowed notifications (spec §6 `user_devices`).
 *
 * @property array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string} $push_subscription
 */
class UserDevice extends Model
{
    /** A sensible cap: phones, tablets and a laptop or two. */
    public const MAX_PER_USER = 10;

    protected $guarded = ['id'];

    protected $hidden = ['push_subscription'];

    protected function casts(): array
    {
        return [
            'push_subscription' => 'array',
            'settings_override' => 'array',
            'last_seen_at' => 'datetime',
            'last_push_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * A friendly default name from the user agent: "iPhone", "Mac (Safari)"…
     */
    public static function nameFromUserAgent(?string $ua): string
    {
        $ua ??= '';

        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Browser',
        };

        if (in_array($device, ['iPhone', 'iPad'], true)) {
            return $device;
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return $browser ? "{$device} ({$browser})" : $device;
    }
}
