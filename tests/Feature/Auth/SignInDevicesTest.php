<?php

use App\Features\SignInDevices;
use App\Models\AuditLog;
use App\Models\SignIn;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

function devicesUser(): User
{
    $user = User::factory()->create();
    Feature::for($user)->activate(SignInDevices::class);

    return $user;
}

function recallerName(): string
{
    return Auth::guard('web')->getRecallerName();
}

/**
 * A request from a different browser: the test client keeps one session
 * store (and guard) across requests, which a real browser wouldn't share.
 */
function freshBrowser(): void
{
    app('auth')->forgetGuards();
    app('session.store')->flush();
}

/**
 * Sign in through a real magic link, as a fresh browser would. Returns its
 * sign-in and the remember cookie it was given (null if not remembered).
 *
 * @return array{SignIn, string|null, TestResponse}
 */
function signInWithLink(User $user, string $userAgent = WINDOWS_CHROME, bool $remember = true): array
{
    $token = Str::random(20);
    $link = $user->magicLinks()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15), 'remember' => $remember]);

    freshBrowser();
    $response = test()->withHeader('User-Agent', $userAgent)
        ->post(URL::signedRoute('login.consume', ['magicLink' => $link->id, 'token' => $token]));

    return [SignIn::latest('id')->firstOrFail(), $response->getCookie(recallerName())?->getValue(), $response];
}

/** A request from a browser that only has its remember cookie (its session ran out). */
function withRememberCookie(string $cookie, string $userAgent = WINDOWS_CHROME): TestResponse
{
    freshBrowser();

    return test()->withHeader('User-Agent', $userAgent)->withCookie(recallerName(), $cookie)->get('/dashboard');
}

/** A sign-in made a while ago, as if by start(). */
function earlierSignIn(User $user, string $userAgent, array $attributes = []): SignIn
{
    return SignIn::create([
        'user_id' => $user->id,
        'remember_hash' => hash('sha256', Str::random(60)),
        'user_agent' => $userAgent,
        'ip_address' => '203.0.113.7',
        'method' => 'magic_link',
        'remembered' => true,
        'signed_in_at' => now()->subDays(3),
        'last_active_at' => now()->subHours(5),
        ...$attributes,
    ]);
}

describe('signing in', function () {
    it('gives each sign-in its own remember token, stored only as a hash', function () {
        $user = User::factory()->create();
        $shared = $user->remember_token;

        [$signIn, $cookie, $response] = signInWithLink($user, IPHONE_SAFARI);

        $token = explode('|', (string) $cookie)[1];

        $response->assertSessionHas(SignIn::SESSION_KEY, $signIn->id);
        expect($signIn)
            ->method->toBe('magic_link')
            ->remembered->toBeTrue()
            ->user_agent->toBe(IPHONE_SAFARI)
            ->remember_hash->toBe(hash('sha256', $token))
            ->and($user->fresh()->remember_token)->toBe($shared);
    });

    it('keeps no remember token when the link was not remembered', function () {
        [, $cookie] = signInWithLink(User::factory()->create(), remember: false);

        expect($cookie)->toBeNull()
            ->and(SignIn::sole())->remembered->toBeFalse()->remember_hash->toBeNull();
    });

    it('lets a remember cookie back in while its sign-in is active, and rolls it on', function () {
        $user = User::factory()->create();
        [, $cookie] = signInWithLink($user);

        $this->travel(30)->days();
        withRememberCookie($cookie)->assertOk()->assertCookie(recallerName());
        expect(SignIn::sole()->last_active_at)->toBeGreaterThan(now()->subMinute());

        // 90 days from the last use, not from signing in.
        $this->travel(80)->days();
        withRememberCookie($cookie)->assertOk();

        $this->travel(91)->days();
        withRememberCookie($cookie)->assertRedirect('/login');
    });

    it('logging out on one browser leaves the others signed in', function () {
        $user = User::factory()->create();
        [$pc] = signInWithLink($user, WINDOWS_CHROME);
        [$phone, $phoneCookie] = signInWithLink($user, IPHONE_SAFARI);

        freshBrowser();
        $this->withSession([SignIn::SESSION_KEY => $pc->id])->actingAs($user)->post('/logout');

        expect($pc->fresh()->signed_out_at)->not->toBeNull()
            ->and($phone->fresh()->signed_out_at)->toBeNull();
        withRememberCookie($phoneCookie, IPHONE_SAFARI)->assertOk();
    });
});

describe('the list', function () {
    it('lists every browser separately, this one first', function () {
        $user = devicesUser();
        earlierSignIn($user, IPHONE_SAFARI);
        earlierSignIn($user, WINDOWS_CHROME, ['method' => 'passkey']);
        earlierSignIn($user, WINDOWS_CHROME, ['signed_out_at' => now()]);
        earlierSignIn($user, WINDOWS_CHROME, ['remembered' => false]); // its session ran out hours ago
        earlierSignIn(User::factory()->create(), IPHONE_SAFARI);
        $here = earlierSignIn($user, $mac = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15');

        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $here->id])->withHeader('User-Agent', $mac)
            ->get('/settings/devices')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/devices')
                ->has('signIns', 3)
                ->where('signIns.0.current', true)
                ->where('signIns.0.name', 'Safari on Mac')
                ->where('signIns.1.current', false)
                ->where('signIns.1.ip_address', '203.0.113.7')
                ->missing('signIns.0.remember_hash'));
    });

    it('stays dark until the flag is on', function () {
        $user = User::factory()->create();
        $signIn = earlierSignIn($user, WINDOWS_CHROME);

        $this->actingAs($user)->get('/settings/devices')->assertInertia(fn (Assert $page) => $page->where('signIns', null)->has('passkeys'));
        $this->actingAs($user)->post('/settings/devices/sign-out-others')->assertNotFound();
        $this->actingAs($user)->delete("/settings/devices/{$signIn->id}")->assertNotFound();
    });
});

describe('signing out one browser', function () {
    it('ends its session and its remember cookie, audited, and nothing else', function () {
        $user = devicesUser();
        [$phone, $phoneCookie] = signInWithLink($user, IPHONE_SAFARI);
        [$pc, $pcCookie] = signInWithLink($user, WINDOWS_CHROME);
        $phoneId = $phone->id;

        freshBrowser();
        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $pc->id])
            ->delete("/settings/devices/{$phoneId}")
            ->assertRedirect('/settings/devices')
            ->assertSessionHas('status', 'signed-out-device');

        expect(SignIn::find($phoneId))->signed_out_at->not->toBeNull()->remember_hash->toBeNull()
            ->and(AuditLog::where('action', 'auth.signed_out_device')->sole()->meta)->toBe(['name' => 'Safari on iPhone']);

        // The phone's live session is turned away, and so is its cookie.
        freshBrowser();
        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $phoneId])->get('/dashboard')->assertRedirect('/login');
        withRememberCookie($phoneCookie, IPHONE_SAFARI)->assertRedirect('/login');

        // The PC carries on.
        withRememberCookie($pcCookie)->assertOk();
    });

    it("can't be dodged by dropping cookies: the session itself names its sign-in", function () {
        $user = devicesUser();
        $signIn = earlierSignIn($user, IPHONE_SAFARI);
        $signIn->signOut();

        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $signIn->id])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    });

    it('signs this browser out like Log out', function () {
        $user = devicesUser();
        $here = earlierSignIn($user, WINDOWS_CHROME);

        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $here->id])
            ->delete("/settings/devices/{$here->id}")
            ->assertRedirect('/');

        $this->assertGuest();
        expect($here->fresh()->signed_out_at)->not->toBeNull();
    });

    it("404s on someone else's sign-in, or one already signed out", function () {
        $user = devicesUser();
        $theirs = earlierSignIn(User::factory()->create(), WINDOWS_CHROME);
        $gone = earlierSignIn($user, WINDOWS_CHROME, ['signed_out_at' => now()]);

        $this->actingAs($user)->delete("/settings/devices/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->delete("/settings/devices/{$gone->id}")->assertNotFound();
        expect($theirs->fresh()->signed_out_at)->toBeNull();
    });
});

describe('signing out everywhere else', function () {
    it('ends every other sign-in, audited, and keeps this one', function () {
        $user = devicesUser();
        [, $phoneCookie] = signInWithLink($user, IPHONE_SAFARI);
        [, $pcCookie] = signInWithLink($user, WINDOWS_CHROME);
        [$here, $hereCookie] = signInWithLink($user, WINDOWS_CHROME);
        $user->forceFill(['remember_token' => 'shared-legacy-token'])->save();

        freshBrowser();
        $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $here->id])
            ->post('/settings/devices/sign-out-others')
            ->assertRedirect('/settings/devices');

        expect(SignIn::whereNotNull('signed_out_at')->count())->toBe(2)
            ->and($user->fresh()->remember_token)->not->toBe('shared-legacy-token')
            ->and(AuditLog::where('action', 'auth.signed_out_others')->sole()->meta)->toBe(['devices' => 2]);

        withRememberCookie($phoneCookie, IPHONE_SAFARI)->assertRedirect('/login');
        withRememberCookie($pcCookie)->assertRedirect('/login');
        withRememberCookie($hereCookie)->assertOk();
    });
});

describe('browsers signed in before sign-ins were kept', function () {
    it('adopts a live session', function () {
        $user = devicesUser();

        $this->actingAs($user)->withHeader('User-Agent', WINDOWS_CHROME)->get('/dashboard')
            ->assertOk()
            ->assertSessionHas(SignIn::SESSION_KEY, SignIn::sole()->id);

        expect(SignIn::sole())->signed_in_at->toBeNull()->remembered->toBeFalse();
    });

    it('swaps an old shared remember cookie for one of its own', function () {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => $legacy = Str::random(60)])->save();
        $oldCookie = $user->id.'|'.$legacy.'|'.Auth::guard('web')->hashPasswordForCookie(null);

        $response = withRememberCookie($oldCookie)->assertOk();

        $newCookie = $response->getCookie(recallerName())->getValue();
        expect($newCookie)->not->toBe($oldCookie)
            ->and(SignIn::sole()->remember_hash)->toBe(hash('sha256', explode('|', $newCookie)[1]));
        withRememberCookie($newCookie)->assertOk();
    });

    it('never lets a signed-out token fall back to the shared one', function () {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => $token = Str::random(60)])->save();
        // A token that both a (signed-out) sign-in and the old column hold.
        SignIn::create(['user_id' => $user->id, 'remember_hash' => hash('sha256', $token), 'remembered' => true, 'last_active_at' => now(), 'signed_out_at' => now()]);

        withRememberCookie($user->id.'|'.$token.'|'.Auth::guard('web')->hashPasswordForCookie(null))->assertRedirect('/login');
    });
});

it('remembers activity, not every request', function () {
    $user = devicesUser();
    $signIn = earlierSignIn($user, WINDOWS_CHROME, ['last_active_at' => now()->subMinutes(2)]);

    $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $signIn->id])->withHeader('User-Agent', WINDOWS_CHROME)->get('/dashboard');
    expect($signIn->fresh()->last_active_at)->toBeLessThan(now()->subMinute());

    $this->travel(10)->minutes();
    $this->actingAs($user)->withSession([SignIn::SESSION_KEY => $signIn->id])->withHeader('User-Agent', WINDOWS_CHROME)->get('/dashboard');
    expect($signIn->fresh()->last_active_at)->toBeGreaterThan(now()->subMinute());
});

it('names browsers', function (string $userAgent, string $name) {
    expect(SignIn::describe($userAgent))->toBe($name);
})->with([
    [WINDOWS_CHROME, 'Chrome on Windows'],
    [IPHONE_SAFARI, 'Safari on iPhone'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1', 'Chrome on iPhone'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'Edge on Windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Mac'],
    ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
    ['curl/8.0', 'Unknown browser'],
]);

it('sends the old settings addresses to the pages they were folded into', function () {
    $this->actingAs(User::factory()->create());

    // The raw Location header: a relative one lands on /settings/settings/….
    $this->get('/settings/appearance')->assertHeader('Location', '/settings/profile');
    $this->get('/settings/passkeys')->assertHeader('Location', '/settings/devices');
    $this->get('/settings/feeds')->assertHeader('Location', '/settings/connected-apps');
});
