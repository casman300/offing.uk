<?php

use App\Features\Crews;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\EmailChangedMail;
use App\Models\AiGrant;
use App\Models\AuditLog;
use App\Models\CrewInvite;
use App\Models\FeedToken;
use App\Models\MagicLink;
use App\Models\Passkey;
use App\Models\User;
use App\Support\ConnectClaude\AccessLevel;
use App\Support\Users\EmailChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;

/*
| Security audit H1: an email change only happens once the new inbox
| confirms it, and the old inbox can undo it.
*/

beforeEach(function () {
    Mail::fake();
});

function requestEmailChange(User $user, string $to): void
{
    test()->actingAs($user)
        ->patch('/settings/profile', ['name' => $user->name, 'email' => $to])
        ->assertSessionHasNoErrors();
}

/** The confirm URL from the queued mail to $to. */
function confirmUrlFor(string $to): string
{
    $url = null;
    Mail::assertQueued(EmailChangeConfirmMail::class, function (EmailChangeConfirmMail $mail) use ($to, &$url) {
        if ($mail->hasTo($to)) {
            $url = $mail->url;
        }

        return $mail->hasTo($to);
    });

    return $url;
}

function revertUrlFor(string $from): string
{
    $url = null;
    Mail::assertQueued(EmailChangedMail::class, function (EmailChangedMail $mail) use ($from, &$url) {
        if ($mail->hasTo($from)) {
            $url = $mail->revertUrl;
        }

        return $mail->hasTo($from);
    });

    return $url;
}

/** A user whose change from old@ to new@ has been requested and confirmed. */
function changedUser(): User
{
    $user = User::factory()->create(['email' => 'old@example.com']);
    requestEmailChange($user, 'new@example.com');
    test()->post(confirmUrlFor('new@example.com'))->assertRedirect();

    return $user->refresh();
}

describe('requesting', function () {
    it('does not change the email, but sends a confirm link to the new address', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);

        requestEmailChange($user, 'new@example.com');

        expect($user->refresh()->email)->toBe('old@example.com')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(EmailChange::pending($user))->toBe('new@example.com');
        Mail::assertQueued(EmailChangeConfirmMail::class, fn ($mail) => $mail->hasTo('new@example.com') && ! $mail->hasTo('old@example.com'));
        expect(AuditLog::where('action', 'auth.email_change.requested')->sole()->meta)->toBe(['to' => 'new@example.com']);

        $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('pendingEmail', 'new@example.com'));
    });

    it('still saves the name and timezone straight away, without any mail', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => 'New Name', 'email' => $user->email, 'timezone' => 'Asia/Tokyo'])
            ->assertSessionHasNoErrors();

        expect($user->refresh()->name)->toBe('New Name')->and($user->timezone)->toBe('Asia/Tokyo');
        Mail::assertNothingQueued();
    });

    it('lets only the newest request be confirmed', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);

        requestEmailChange($user, 'first@example.com');
        $first = confirmUrlFor('first@example.com');
        requestEmailChange($user, 'second@example.com');

        $this->post($first)->assertRedirect()->assertSessionHasErrors('email');

        expect($user->refresh()->email)->toBe('old@example.com')
            ->and(EmailChange::pending($user))->toBe('second@example.com');
    });

    it('can be cancelled', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');

        $this->post('/settings/profile/email/cancel')->assertRedirect('/settings/profile');

        expect(EmailChange::pending($user))->toBeNull();
        $this->post($url)->assertSessionHasErrors('email');
        expect($user->refresh()->email)->toBe('old@example.com');
        expect(AuditLog::where('action', 'auth.email_change.cancelled')->exists())->toBeTrue();
    });

    it('refuses an address another account has', function () {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => $user->name, 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        Mail::assertNothingQueued();
    });

    it('is rate limited', function () {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            requestEmailChange($user, "try{$i}@example.com");
        }

        $this->actingAs($user)
            ->patch('/settings/profile', ['name' => $user->name, 'email' => 'six@example.com'])
            ->assertSessionHasErrors('email');
    });
});

describe('confirming', function () {
    it('shows a button without changing anything when the link is opened', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/email-change')
            ->where('mode', 'confirm')
            ->where('email', 'new@example.com')
            ->where('action', $url));

        expect($user->refresh()->email)->toBe('old@example.com');
        expect(MagicLink::where('purpose', MagicLink::EMAIL_CONFIRM)->sole()->used_at)->toBeNull();
    });

    it('swaps the email on the button, and tells the old address how to undo it', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');

        $this->post(confirmUrlFor('new@example.com'))->assertRedirect('/settings/profile');

        expect($user->refresh()->email)->toBe('new@example.com')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(EmailChange::pending($user))->toBeNull();
        Mail::assertQueued(EmailChangedMail::class, fn ($mail) => $mail->hasTo('old@example.com') && $mail->newEmail === 'new@example.com');
        expect(AuditLog::where('action', 'auth.email_change.confirmed')->sole()->meta)->toBe(['from' => 'old@example.com', 'to' => 'new@example.com']);
    });

    it('works from another device without signing anyone in', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');
        auth()->logout();

        $this->post($url)->assertRedirect('/login')->assertSessionHas('status');

        $this->assertGuest();
        expect($user->refresh()->email)->toBe('new@example.com');
    });

    it('works once', function () {
        $user = User::factory()->create();
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');

        $this->post($url)->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasErrors('email');
        $this->get($url)->assertRedirect()->assertSessionHasErrors('email');
    });

    it('expires after a day', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');

        $this->travel(EmailChange::CONFIRM_MINUTES + 1)->minutes();

        $this->post($url)->assertForbidden();
        expect($user->refresh()->email)->toBe('old@example.com');
    });

    it('rejects a tampered link', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');

        $this->post(confirmUrlFor('new@example.com').'x')->assertForbidden();
        expect($user->refresh()->email)->toBe('old@example.com');
    });

    it('fails if someone else took the address in the meantime', function () {
        $user = User::factory()->create(['email' => 'old@example.com']);
        requestEmailChange($user, 'new@example.com');
        $url = confirmUrlFor('new@example.com');
        User::factory()->create(['email' => 'new@example.com']);

        $this->post($url)->assertSessionHasErrors('email');

        expect($user->refresh()->email)->toBe('old@example.com');
        Mail::assertNotQueued(EmailChangedMail::class);
    });

    it('never works as a sign-in link', function () {
        $user = User::factory()->create();
        requestEmailChange($user, 'new@example.com');
        auth()->logout();

        $link = MagicLink::where('purpose', MagicLink::EMAIL_CONFIRM)->sole();
        $token = Str::afterLast(parse_url(confirmUrlFor('new@example.com'), PHP_URL_PATH), '/');

        $this->get(URL::signedRoute('login.consume', ['magicLink' => $link->id, 'token' => $token]))
            ->assertRedirect('/login');

        $this->assertGuest();
        expect($link->refresh()->used_at)->toBeNull();
    });
});

describe('what the email unlocks', function () {
    it('no longer lets you claim a crew invite sent to someone else', function () {
        $captain = User::factory()->create();
        Feature::for($captain)->activate(Crews::class);
        $this->actingAs($captain)->post('/crew', ['name' => 'Home']);
        $this->post('/crew/'.DB::table('crews')->value('id').'/invites', ['email' => 'partner@example.com', 'role' => 'crew']);
        $invite = CrewInvite::sole();

        $attacker = User::factory()->create();
        Feature::for($attacker)->activate(Crews::class);
        requestEmailChange($attacker, 'partner@example.com');

        expect($attacker->refresh()->email)->not->toBe('partner@example.com');
        $this->actingAs($attacker)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();
    });

    it('only lets a verified address accept an invite', function () {
        $user = User::factory()->unverified()->create(['email' => 'partner@example.com']);
        Feature::for($user)->activate(Crews::class);
        $crew = DB::table('crews')->insertGetId(['name' => 'Home', 'created_at' => now(), 'updated_at' => now()]);
        $invite = CrewInvite::create(['crew_id' => $crew, 'email' => 'partner@example.com', 'role' => 'crew', 'expires_at' => now()->addDay()]);

        expect(CrewInvite::query()->for($user)->exists())->toBeFalse();
        $this->actingAs($user)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        $user->forceFill(['email_verified_at' => now()])->save();
        expect(CrewInvite::query()->for($user)->exists())->toBeTrue();
    });

    it('no longer lets you become an admin by claiming an ADMIN_EMAILS address', function () {
        config(['offing.admin_emails' => ['boss@example.com']]);
        $user = User::factory()->create();

        requestEmailChange($user, 'boss@example.com');

        expect($user->refresh()->isAdmin())->toBeFalse();
        $this->actingAs($user)->get('/admin/features')->assertNotFound();
    });

    it('only treats a verified ADMIN_EMAILS address as admin', function () {
        config(['offing.admin_emails' => ['boss@example.com']]);

        expect(User::factory()->unverified()->create(['email' => 'boss@example.com'])->isAdmin())->toBeFalse()
            ->and(User::factory()->create(['email' => 'boss2@example.com'])->isAdmin())->toBeFalse();

        config(['offing.admin_emails' => ['boss@example.com', 'boss2@example.com']]);
        expect(User::where('email', 'boss2@example.com')->sole()->isAdmin())->toBeTrue();
    });
});

describe('undoing ("this wasn\'t me")', function () {
    it('shows a button without changing anything when the link is opened', function () {
        $user = changedUser();
        $url = revertUrlFor('old@example.com');

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/email-change')
            ->where('mode', 'revert')
            ->where('email', 'old@example.com'));

        expect($user->refresh()->email)->toBe('new@example.com');
    });

    it('puts the old address back and removes whatever was planted since the change', function () {
        config(['session.driver' => 'database']); // as in production; tests default to array
        $this->travel(-1)->hours();
        $user = User::factory()->create(['email' => 'old@example.com']);
        $before = Passkey::forceCreate(['user_id' => $user->id, 'name' => 'Mine', 'credential_id' => 'mine', 'credential' => '{}']);
        $this->travelBack();

        requestEmailChange($user, 'new@example.com');
        $this->post(confirmUrlFor('new@example.com'));
        $user->refresh();
        $rememberToken = $user->remember_token;

        // What a hijacker might add once the address is theirs.
        $this->travel(1)->minutes();
        Passkey::forceCreate(['user_id' => $user->id, 'name' => 'Theirs', 'credential_id' => 'theirs', 'credential' => '{}']);
        FeedToken::mint($user, 'Theirs');
        $user->devices()->create(['name' => 'Theirs', 'endpoint_hash' => str_repeat('a', 64), 'push_subscription' => ['endpoint' => 'https://fcm.googleapis.com/x']]);
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/cb'], false);
        $grant = AiGrant::create(['user_id' => $user->id, 'oauth_client_id' => $client->id, 'level' => AccessLevel::Read]);
        $token = Passport::token()->forceFill(['id' => Str::random(40), 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => [], 'revoked' => false, 'expires_at' => now()->addHour()]);
        $token->save();
        MagicLink::issue($user);
        DB::table('sessions')->insert(['id' => 'their-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        // Undone from a fresh browser: the old inbox, not the hijacked session.
        auth()->logout();
        $this->post(revertUrlFor('old@example.com'))->assertRedirect('/login')->assertSessionHas('status');

        $user->refresh();
        expect($user->email)->toBe('old@example.com')
            ->and($user->remember_token)->not->toBe($rememberToken)
            ->and($user->passkeys()->pluck('name')->all())->toBe([$before->name])
            ->and($user->feedTokens()->active()->count())->toBe(0)
            ->and($user->devices()->count())->toBe(0)
            ->and(AiGrant::find($grant->id))->toBeNull()
            ->and((bool) $token->refresh()->revoked)->toBeTrue()
            ->and($user->magicLinks()->open()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

        expect(AuditLog::where('action', 'auth.email_change.reverted')->sole()->meta)->toMatchArray([
            'from' => 'new@example.com',
            'to' => 'old@example.com',
            'passkeys' => 1,
            'feed_tokens' => 1,
            'devices' => 1,
            'ai_connections' => 1,
        ]);
    });

    it('signs out the session it was clicked from, if that is the account', function () {
        $user = changedUser();

        $this->actingAs($user)->post(revertUrlFor('old@example.com'))->assertRedirect('/login');

        $this->assertGuest();
    });

    it('still works after a second change, so a hijacker cannot outrun it', function () {
        $user = changedUser();
        $revert = revertUrlFor('old@example.com');

        requestEmailChange($user, 'newer@example.com');
        $this->post(confirmUrlFor('newer@example.com'));
        expect($user->refresh()->email)->toBe('newer@example.com');

        auth()->logout();
        $this->post($revert)->assertRedirect('/login');

        expect($user->refresh()->email)->toBe('old@example.com');
    });

    it('works once, and lasts a week', function () {
        $user = changedUser();
        $url = revertUrlFor('old@example.com');

        $this->travel(EmailChange::REVERT_MINUTES - 5)->minutes();
        $this->post($url)->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasErrors('email');

        $user->forceFill(['email' => 'new@example.com'])->save();
        $this->travel(10)->minutes();
        $this->post($url)->assertForbidden();
    });

    it('cannot restore an address someone else now has', function () {
        $user = changedUser();
        User::factory()->create(['email' => 'old@example.com']);

        auth()->logout();
        $this->post(revertUrlFor('old@example.com'))->assertRedirect('/login')->assertSessionHasErrors('email');

        expect($user->refresh()->email)->toBe('new@example.com');
    });
});

it('renders both emails', function () {
    $user = User::factory()->create(['name' => 'Charlie']);

    expect((new EmailChangeConfirmMail($user, 'new@example.com', 'https://offing.test/confirm'))->render())
        ->toContain('new@example.com')->toContain('https://offing.test/confirm')->toContain('24 hours');
    expect((new EmailChangedMail($user, 'old@example.com', 'new@example.com', 'https://offing.test/revert'))->render())
        ->toContain('old@example.com')->toContain('new@example.com')->toContain('https://offing.test/revert')->toContain('7 days');
});
