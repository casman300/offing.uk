<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Auth\SessionGuard;

/**
 * A browser the user is signed in on, for Settings → Sign-in & devices
 * (spec §15). Each browser carries a long-lived `offing_device` cookie;
 * only its hash is stored. Signing out (here, or "everywhere else") sets
 * `signed_out_at`, and TrackSignIns refuses a browser whose row says so.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_hash
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property string|null $method
 * @property bool $remembered
 * @property Carbon|null $signed_in_at
 * @property Carbon $last_active_at
 * @property Carbon|null $signed_out_at
 */
class SignIn extends Model
{
    public const COOKIE = 'offing_device';

    /** 400 days, the longest a browser will keep a cookie. */
    private const COOKIE_MINUTES = 60 * 24 * 400;

    /** last_active_at is a rough "when did I last use it", not a log. */
    private const ACTIVITY_GRANULARITY_MINUTES = 5;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'remembered' => 'boolean',
            'signed_in_at' => 'datetime',
            'last_active_at' => 'datetime',
            'signed_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A fresh sign-in on this browser, by magic link or passkey.
     */
    public static function record(Request $request, User $user, string $method, bool $remembered): self
    {
        return self::updateOrCreate(
            ['user_id' => $user->id, 'device_hash' => self::deviceHash($request)],
            [
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'method' => $method,
                'remembered' => $remembered,
                'signed_in_at' => now(),
                'last_active_at' => now(),
                'signed_out_at' => null,
            ],
        );
    }

    /**
     * This browser's row for the user, created on first sight for browsers
     * that signed in before rows were kept (signed_in_at stays unknown).
     */
    public static function current(Request $request, User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->id, 'device_hash' => self::deviceHash($request)],
            [
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'remembered' => self::hasRememberCookie($request),
                'last_active_at' => now(),
            ],
        );
    }

    /**
     * This browser has signed out of the user's account. A browser without
     * a device cookie has no row to update.
     */
    public static function markSignedOut(Request $request, User $user): void
    {
        $value = $request->cookie(self::COOKIE);

        if (is_string($value)) {
            self::where('user_id', $user->id)->where('device_hash', hash('sha256', $value))->whereNull('signed_out_at')->update(['signed_out_at' => now()]);
        }
    }

    /**
     * Sign the user out of every browser but $except (and its session): rows
     * are marked signed out, which TrackSignIns enforces; database sessions
     * go; and the shared remember token changes, so no remember cookie
     * works any more — re-log $except in to give it a fresh one. Returns
     * how many browsers were still signed in.
     */
    public static function signOutEverywhere(User $user, ?self $except = null, ?string $exceptSessionId = null): int
    {
        $others = self::where('user_id', $user->id)->when($except, fn (Builder $q) => $q->whereKeyNot($except->id));
        $count = (clone $others)->active()->count();
        $others->whereNull('signed_out_at')->update(['signed_out_at' => now()]);

        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        return $count;
    }

    public function touchActivity(Request $request): void
    {
        if ($this->last_active_at->gt(now()->subMinutes(self::ACTIVITY_GRANULARITY_MINUTES)) && $this->user_agent === $request->userAgent()) {
            return;
        }

        $this->forceFill([
            'last_active_at' => now(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ])->save();
    }

    /**
     * Still signed in: not signed out, and used recently enough that the
     * session (or, if remembered, the remember cookie) hasn't run out.
     *
     * @param  Builder<SignIn>  $query
     */
    public function scopeActive(Builder $query): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        $query->whereNull('signed_out_at')->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('remembered', true)->where('last_active_at', '>', now()->subMinutes($guard->getRememberDuration())))
            ->orWhere(fn (Builder $q) => $q->where('remembered', false)->where('last_active_at', '>', now()->subMinutes((int) config('session.lifetime'))))
        );
    }

    /**
     * "Chrome on Windows", "Safari on iPhone"…
     */
    public static function describe(?string $ua): string
    {
        $ua ??= '';

        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'Chromebook',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/'), str_contains($ua, 'EdgiOS/'), str_contains($ua, 'EdgA/') => 'Edge',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/'), str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        return match (true) {
            $browser && $device => "{$browser} on {$device}",
            default => $browser ?? $device ?? 'Unknown browser',
        };
    }

    /**
     * This browser's device cookie, hashed, minting the cookie on first use.
     */
    private static function deviceHash(Request $request): string
    {
        $value = $request->cookie(self::COOKIE);

        if (! is_string($value) || strlen($value) !== 40) {
            $value = Str::random(40);
            $request->cookies->set(self::COOKIE, $value);
            Cookie::queue(self::COOKIE, $value, self::COOKIE_MINUTES);
        }

        return hash('sha256', $value);
    }

    public static function isCurrentDevice(Request $request, self $signIn): bool
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && hash_equals($signIn->device_hash, hash('sha256', $value));
    }

    public static function hasRememberCookie(Request $request): bool
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $request->cookies->has($guard->getRecallerName());
    }
}
