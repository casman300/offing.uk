<?php

use App\Features\SignInDevices;
use App\Models\AuditLog;
use App\Models\SignIn;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

/** A browser of $user's that signed in a while ago: its device cookie value. */
function signedInBrowser(User $user, string $userAgent, array $attributes = []): string
{
    $cookie = Str::random(40);

    SignIn::create([
        'user_id' => $user->id,
        'device_hash' => hash('sha256', $cookie),
        'user_agent' => $userAgent,
        'method' => 'magic_link',
        'remembered' => true,
        'signed_in_at' => now()->subDays(3),
        'last_active_at' => now()->subHours(5),
        ...$attributes,
    ]);

    return $cookie;
}

function devicesUser(): User
{
    $user = User::factory()->create();
    Feature::for($user)->activate(SignInDevices::class);

    return $user;
}

it('records a magic-link sign-in against this browser', function () {
    $user = User::factory()->create();
    $magicLink = $user->magicLinks()->create(['token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addMinutes(15), 'remember' => true]);

    $this->withHeader('User-Agent', IPHONE_SAFARI)
        ->post(URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => 'token']))
        ->assertCookie(SignIn::COOKIE);

    expect(SignIn::sole())
        ->user_id->toBe($user->id)
        ->method->toBe('magic_link')
        ->remembered->toBeTrue()
        ->signed_in_at->not->toBeNull()
        ->user_agent->toBe(IPHONE_SAFARI);
});

it('signing in again on the same browser updates its row', function () {
    $user = User::factory()->create();
    $cookie = signedInBrowser($user, WINDOWS_CHROME, ['signed_out_at' => now()->subDay()]);
    $magicLink = $user->magicLinks()->create(['token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addMinutes(15), 'remember' => false]);

    $this->withCookie(SignIn::COOKIE, $cookie)
        ->post(URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => 'token']));

    expect(SignIn::sole())
        ->signed_out_at->toBeNull()
        ->remembered->toBeFalse()
        ->signed_in_at->toBeGreaterThan(now()->subMinute());
});

it('lists every browser separately, this one first', function () {
    $user = devicesUser();
    signedInBrowser($user, IPHONE_SAFARI);
    signedInBrowser($user, WINDOWS_CHROME, ['method' => 'passkey']);
    signedInBrowser($user, WINDOWS_CHROME, ['signed_out_at' => now()]);
    signedInBrowser($user, WINDOWS_CHROME, ['remembered' => false]); // its session ran out hours ago
    signedInBrowser(User::factory()->create(), IPHONE_SAFARI);

    $response = $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15')
        ->get('/settings/devices');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/devices')
        ->has('signIns', 3)
        ->where('signIns.0.current', true)
        ->where('signIns.0.name', 'Safari on Mac')
        ->where('signIns.1.current', false)
        ->where('signIns.2.current', false)
        ->missing('signIns.0.device_hash'));

    expect(collect($response->viewData('page')['props']['signIns'])->pluck('name')->all())
        ->toContain('Safari on iPhone', 'Chrome on Windows');
});

it('keeps the list dark until the flag is on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/devices')->assertInertia(fn (Assert $page) => $page->where('signIns', null)->has('passkeys'));
    $this->actingAs($user)->post('/settings/devices/sign-out-others')->assertNotFound();
});

it('signs out everywhere else, audited, and keeps this browser signed in', function () {
    $user = devicesUser();
    $user->forceFill(['remember_token' => 'old-token'])->save();
    $phone = signedInBrowser($user, IPHONE_SAFARI);
    $pc = signedInBrowser($user, WINDOWS_CHROME);
    $here = signedInBrowser($user, WINDOWS_CHROME);

    $this->actingAs($user)->withCookie(SignIn::COOKIE, $here)
        ->post('/settings/devices/sign-out-others')
        ->assertRedirect('/settings/devices')
        ->assertSessionHas('status', 'signed-out-others');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(SignIn::whereNotNull('signed_out_at')->count())->toBe(2)
        ->and(SignIn::where('device_hash', hash('sha256', $here))->sole()->signed_out_at)->toBeNull()
        ->and(AuditLog::where('action', 'auth.signed_out_others')->sole()->meta)->toBe(['devices' => 2]);

    // The phone's session is turned away on its next request.
    app('auth')->forgetGuards();
    $this->actingAs($user)->withCookie(SignIn::COOKIE, $phone)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('marks this browser signed out when it logs out', function () {
    $user = devicesUser();
    $cookie = signedInBrowser($user, WINDOWS_CHROME);

    $this->actingAs($user)->withCookie(SignIn::COOKIE, $cookie)->post('/logout');

    expect(SignIn::sole()->signed_out_at)->not->toBeNull();
});

it('remembers activity, not every request', function () {
    $user = devicesUser();
    $cookie = signedInBrowser($user, WINDOWS_CHROME, ['last_active_at' => now()->subMinutes(2)]);

    $this->actingAs($user)->withHeader('User-Agent', WINDOWS_CHROME)->withCookie(SignIn::COOKIE, $cookie)->get('/settings/devices');
    expect(SignIn::sole()->last_active_at)->toBeLessThan(now()->subMinute());

    $this->travel(10)->minutes();
    $this->actingAs($user)->withHeader('User-Agent', WINDOWS_CHROME)->withCookie(SignIn::COOKIE, $cookie)->get('/settings/devices');
    expect(SignIn::sole()->last_active_at)->toBeGreaterThan(now()->subMinute());
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
