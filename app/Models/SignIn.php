<?php

namespace App\Models;

use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One sign-in on one browser, for Settings → Sign-in & devices (spec §15).
 *
 * What ties a browser to its row is server-side: the row's id in the
 * session, and the row's own remember-me token (only its hash is stored;
 * SignInUserProvider checks it). Nothing the browser can choose to send
 * or withhold keeps a signed-out row alive: TrackSignIns turns away any
 * session whose row is signed out or has run out, and a revoked token no
 * longer signs anyone in.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $remember_hash
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
    public const SESSION_KEY = 'sign_in_id';

    /** last_active_at is a rough "when did I last use it", not a log. */
    private const ACTIVITY_GRANULARITY_MINUTES = 5;

    protected $guarded = ['id'];

    protected $hidden = ['remember_hash'];

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
     * Sign $user in on this browser (magic link or passkey): a fresh
     * session id (no fixation), and, if remembered, a remember cookie
     * carrying a token of this sign-in's own.
     */
    public static function start(Request $request, User $user, string $method, bool $remember): self
    {
        $token = $remember ? self::login($user) : null;

        if (! $remember) {
            Auth::guard('web')->login($user);
        }

        $request->session()->regenerate();

        $signIn = self::create([
            'user_id' => $user->id,
            'remember_hash' => $token === null ? null : hash('sha256', $token),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'method' => $method,
            'remembered' => $remember,
            'signed_in_at' => now(),
            'last_active_at' => now(),
        ]);

        $request->session()->put(self::SESSION_KEY, $signIn->id);

        return $signIn;
    }

    /**
     * The sign-in this session belongs to (signed out or not: the caller
     * checks). A session without one of this user's (from before sign-ins
     * were kept, or just restored from a remember cookie) gets one here.
     */
    public static function forRequest(Request $request, User $user): self
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $signIn = is_numeric($id) ? self::where('user_id', $user->id)->whereKey((int) $id)->first() : null;

        if ($signIn !== null) {
            return $signIn;
        }

        $token = self::recaller($request)?->token();
        $signIn = $token === null ? null : self::where('user_id', $user->id)->where('remember_hash', hash('sha256', $token))->first();

        if ($signIn === null) {
            // Signed in before sign-ins were kept. A remember cookie of
            // that age matches the shared users.remember_token, so swap it
            // for one of this sign-in's own.
            $signIn = new self([
                'user_id' => $user->id,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'remembered' => $token !== null,
                'last_active_at' => now(),
            ]);

            if ($token !== null) {
                $signIn->remember_hash = hash('sha256', self::login($user));
            }

            $signIn->save();
        }

        $request->session()->put(self::SESSION_KEY, $signIn->id);

        return $signIn;
    }

    public static function isCurrent(Request $request, self $signIn): bool
    {
        return (int) $request->session()->get(self::SESSION_KEY) === $signIn->id;
    }

    /**
     * Not signed out, and used recently enough that its session (or, if
     * remembered, its remember cookie) hasn't run out. Remembered sign-ins
     * roll: each use pushes the end back.
     */
    public function isActive(): bool
    {
        return $this->signed_out_at === null
            && $this->last_active_at->gt(now()->subMinutes(self::lifetimeMinutes($this->remembered)));
    }

    /**
     * @param  Builder<SignIn>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('signed_out_at')->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('remembered', true)->where('last_active_at', '>', now()->subMinutes(self::lifetimeMinutes(true))))
            ->orWhere(fn (Builder $q) => $q->where('remembered', false)->where('last_active_at', '>', now()->subMinutes(self::lifetimeMinutes(false))))
        );
    }

    public function signOut(): void
    {
        $this->forceFill(['signed_out_at' => now(), 'remember_hash' => null])->save();
    }

    /**
     * Sign the user out of every sign-in but $except. Beyond the rows and
     * their tokens, this also retires the shared users.remember_token and
     * any database session but $exceptSessionId, so browsers signed in
     * before sign-ins were kept go too. Returns how many were signed in.
     */
    public static function signOutEverywhere(User $user, ?self $except = null, ?string $exceptSessionId = null): int
    {
        $others = self::where('user_id', $user->id)->whereNull('signed_out_at')->when($except, fn (Builder $q) => $q->whereKeyNot($except->id));
        $count = (clone $others)->active()->count();
        $others->update(['signed_out_at' => now(), 'remember_hash' => null]);

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();
        }

        return $count;
    }

    /**
     * Note this use, at most every few minutes. A remembered sign-in's
     * cookie is re-sent then too, so it lasts as long as the row does.
     */
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

        $recaller = self::recaller($request);

        if ($this->remembered && $recaller !== null && $this->remember_hash !== null && hash_equals($this->remember_hash, hash('sha256', $recaller->token()))) {
            Cookie::queue(self::webGuard()->getRecallerName(), (string) $request->cookies->get(self::webGuard()->getRecallerName()), self::lifetimeMinutes(true));
        }
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
     * Log $user in with a remember cookie carrying a new token of its own,
     * returning that token. The guard writes whatever token the user has
     * into the cookie; the shared users.remember_token is never changed.
     */
    private static function login(User $user): string
    {
        $shared = $user->getRememberToken();
        $user->setRememberToken($token = Str::random(60));

        Auth::guard('web')->login($user, remember: true);

        $user->setRememberToken($shared);

        return $token;
    }

    private static function recaller(Request $request): ?Recaller
    {
        $value = $request->cookies->get(self::webGuard()->getRecallerName());
        $recaller = is_string($value) ? new Recaller($value) : null;

        return $recaller?->valid() ? $recaller : null;
    }

    /**
     * A remember cookie lasts the web guard's `remember` minutes; a plain
     * session, the session lifetime. Plus the activity granularity, since
     * last_active_at can lag the real last request by that much.
     */
    private static function lifetimeMinutes(bool $remembered): int
    {
        return self::ACTIVITY_GRANULARITY_MINUTES + ($remembered
            ? (int) config('auth.guards.web.remember', 60 * 24 * 400)
            : (int) config('session.lifetime'));
    }

    private static function webGuard(): SessionGuard
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');

        return $guard;
    }
}
