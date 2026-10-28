<?php

use App\Http\Controllers\Auth\PasskeySetupController;
use App\Mail\PasskeyAddedMail;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAuthenticator;

beforeEach(function () {
    config(['app.url' => 'http://localhost']);
    Mail::fake();
});

/** Register $authenticator for $user through the real settings endpoints. */
function registerPasskey(User $user, FakeAuthenticator $authenticator, array $override = [], array $extra = []): TestResponse
{
    $options = test()->actingAs($user)->postJson('/settings/passkeys/options')->assertOk()->getContent();

    return test()->actingAs($user)->post('/settings/passkeys', [
        'credential' => $authenticator->register($options, $override),
        ...$extra,
    ]);
}

/** Sign in as a guest with $authenticator through the real login endpoints. */
function passkeyLogin(FakeAuthenticator $authenticator, array $override = []): TestResponse
{
    $options = test()->postJson('/login/passkey/options')->assertOk()->getContent();

    return test()->post('/login/passkey', ['credential' => $authenticator->assert($options, $override)]);
}

function enrolled(?User $user = null): array
{
    $user ??= User::factory()->create();
    $authenticator = new FakeAuthenticator;
    registerPasskey($user, $authenticator)->assertSessionHasNoErrors();
    app('auth')->forgetGuards();

    return [$user, $authenticator];
}

describe('registration', function () {
    it('adds a passkey, audits it and emails the owner', function () {
        $user = User::factory()->create();

        registerPasskey($user, new FakeAuthenticator, extra: ['device' => 'iPhone'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/devices');

        $passkey = Passkey::sole();
        expect($passkey->user_id)->toBe($user->id)
            ->and($passkey->name)->toBe('iPhone')
            ->and(AuditLog::where('action', 'auth.passkey.registered')->where('subject_id', $passkey->id)->exists())->toBeTrue();

        Mail::assertQueued(PasskeyAddedMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->passkey->is($passkey));
    });

    it('asks for a discoverable, user-verified credential bound to this host', function () {
        $options = $this->actingAs(User::factory()->create())->postJson('/settings/passkeys/options')->json();

        expect($options['rp']['id'])->toBe('localhost')
            ->and($options['authenticatorSelection']['userVerification'])->toBe('required')
            ->and($options['authenticatorSelection']['residentKey'])->toBe('required')
            ->and($options['attestation'])->toBe('none')
            ->and($options['user'])->not->toHaveKey('id', (string) User::first()->id);
    });

    it('excludes passkeys the user already has', function () {
        [$user, $authenticator] = enrolled();

        $options = $this->actingAs($user)->postJson('/settings/passkeys/options')->json();

        expect(collect($options['excludeCredentials'])->pluck('id')->all())->toBe([FakeAuthenticator::b64($authenticator->credentialId)]);
    });

    it('rejects a credential that fails a ceremony check', function (array $override) {
        $user = User::factory()->create();

        registerPasskey($user, new FakeAuthenticator, $override)->assertSessionHasErrors('passkey');

        expect(Passkey::count())->toBe(0);
        Mail::assertNothingQueued();
    })->with([
        'another origin' => [['origin' => 'https://evil.example']],
        'a lookalike subdomain' => [['origin' => 'http://login.localhost']],
        'another RP' => [['rpId' => 'evil.example']],
        'no user verification' => [['uv' => false]],
        'a different challenge' => [['challenge' => FakeAuthenticator::b64(random_bytes(32))]],
    ]);

    it('rejects a registration with no challenge in the session (replay)', function () {
        $user = User::factory()->create();
        $authenticator = new FakeAuthenticator;
        $options = $this->actingAs($user)->postJson('/settings/passkeys/options')->getContent();
        $credential = $authenticator->register($options);

        $this->actingAs($user)->post('/settings/passkeys', ['credential' => $credential])->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/settings/passkeys', ['credential' => $credential])->assertSessionHasErrors('passkey');

        expect(Passkey::count())->toBe(1);
    });

    it('requires sign-in', function () {
        $this->postJson('/settings/passkeys/options')->assertUnauthorized();
        $this->get('/settings/devices')->assertRedirect('/login');
    });
});

describe('sign-in', function () {
    it('signs the owner in with a remembered session and audits it', function () {
        [$user, $authenticator] = enrolled();
        $user->forceFill(['remember_token' => null])->save();

        passkeyLogin($authenticator)->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        expect($user->fresh()->remember_token)->not->toBeNull()
            ->and(Passkey::sole()->last_used_at)->not->toBeNull()
            ->and(json_decode(Passkey::sole()->credential, true)['counter'])->toBe(1)
            ->and(AuditLog::where('action', 'auth.login')->latest('id')->first()->meta)->toBe(['method' => 'passkey']);
    });

    it('offers any discoverable passkey (no allowCredentials) with user verification required', function () {
        $options = $this->postJson('/login/passkey/options')->json();

        expect($options['rpId'])->toBe('localhost')
            ->and($options['userVerification'])->toBe('required')
            ->and($options['allowCredentials'] ?? [])->toBe([]);
    });

    it('rejects an assertion that fails a ceremony check', function (array $override) {
        [, $authenticator] = enrolled();

        passkeyLogin($authenticator, $override)->assertSessionHasErrors('passkey');

        $this->assertGuest();
        expect(AuditLog::where('action', 'auth.passkey.rejected')->exists())->toBeTrue();
    })->with([
        'another origin' => [['origin' => 'https://evil.example']],
        'another RP' => [['rpId' => 'evil.example']],
        'no user verification' => [['uv' => false]],
        'a different challenge' => [['challenge' => FakeAuthenticator::b64(random_bytes(32))]],
        'someone else’s user handle' => [['userHandle' => random_bytes(32)]],
    ]);

    it('rejects an unknown credential', function () {
        enrolled();
        $stranger = new FakeAuthenticator;
        $stranger->register($this->actingAs(User::factory()->create())->postJson('/settings/passkeys/options')->getContent());
        app('auth')->forgetGuards();
        auth()->logout();

        passkeyLogin($stranger)->assertSessionHasErrors('passkey');
        $this->assertGuest();
    });

    it('rejects a replayed assertion', function () {
        [, $authenticator] = enrolled();
        $options = $this->postJson('/login/passkey/options')->getContent();
        $credential = $authenticator->assert($options);

        $this->post('/login/passkey', ['credential' => $credential])->assertRedirect('/dashboard');
        auth()->logout();

        $this->post('/login/passkey', ['credential' => $credential])->assertSessionHasErrors('passkey');
        $this->assertGuest();
    });

    it('rejects a cloned authenticator whose counter goes backwards', function () {
        [, $authenticator] = enrolled();
        $authenticator->signCount = 10;
        passkeyLogin($authenticator)->assertRedirect('/dashboard');
        auth()->logout();

        $authenticator->signCount = 3;
        passkeyLogin($authenticator)->assertSessionHasErrors('passkey');
        $this->assertGuest();
    });

    it('rejects garbage', function () {
        $this->postJson('/login/passkey/options');
        $this->post('/login/passkey', ['credential' => '{"nope":true}'])->assertSessionHasErrors('passkey');
        $this->post('/login/passkey', ['credential' => ''])->assertSessionHasErrors('credential');
        $this->assertGuest();
    });
});

describe('setup prompt', function () {
    it('follows a magic-link sign-in until dismissed on this device', function () {
        $this->actingAs(User::factory()->create())->get('/passkeys/setup')
            ->assertInertia(fn (Assert $page) => $page->component('auth/passkey-setup'));

        $this->post('/passkeys/setup/dismiss')
            ->assertRedirect('/dashboard')
            ->assertCookie(PasskeySetupController::DISMISS_COOKIE);
    });

    it('continues to the dashboard after registering from the prompt', function () {
        registerPasskey(User::factory()->create(), new FakeAuthenticator, extra: ['from_setup' => true])
            ->assertRedirect('/dashboard');
    });
});

describe('managing passkeys', function () {
    it('lists, renames and removes my passkeys, auditing each change', function () {
        [$user] = enrolled();
        $passkey = Passkey::sole();

        $this->actingAs($user)->get('/settings/devices')
            ->assertInertia(fn (Assert $page) => $page->has('passkeys', 1)->missing('passkeys.0.credential'));

        $this->actingAs($user)->patch("/settings/passkeys/{$passkey->id}", ['name' => 'Work laptop'])->assertRedirect('/settings/devices');
        expect($passkey->fresh()->name)->toBe('Work laptop');

        $this->actingAs($user)->delete("/settings/passkeys/{$passkey->id}")->assertRedirect('/settings/devices');
        expect(Passkey::count())->toBe(0)
            ->and(AuditLog::whereIn('action', ['auth.passkey.renamed', 'auth.passkey.removed'])->count())->toBe(2);
    });

    it('404s on someone else’s passkey', function () {
        enrolled();
        $passkey = Passkey::sole();
        $me = User::factory()->create();

        $this->actingAs($me)->patch("/settings/passkeys/{$passkey->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->actingAs($me)->delete("/settings/passkeys/{$passkey->id}")->assertNotFound();
        expect($passkey->fresh())->not->toBeNull();
    });

    it('stops a removed passkey from signing in', function () {
        [$user, $authenticator] = enrolled();
        $this->actingAs($user)->delete('/settings/passkeys/'.Passkey::sole()->id);
        auth()->logout();

        passkeyLogin($authenticator)->assertSessionHasErrors('passkey');
        $this->assertGuest();
    });
});

it('renders the passkey-added email', function () {
    [$user] = enrolled(User::factory()->create(['name' => 'Charlie']));

    $html = (new PasskeyAddedMail($user, Passkey::sole()))->render();

    expect($html)->toContain('Hey Charlie')
        ->toContain(Passkey::sole()->name)
        ->toContain(route('devices.index'));
});
