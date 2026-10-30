<?php

use App\Features\Crews;
use App\Features\Shipshape;
use App\Http\Controllers\Auth\PasskeySetupController;
use App\Mail\CrewInviteMail;
use App\Mail\CrewJoinConfirmMail;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewInvite;
use App\Models\CrewMember;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

function sailor(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Feature::for($user)->activate(Crews::class);

    return $user;
}

/** A crew with $captain aboard as captain and any $crew aboard as crew. */
function crewOf(User $captain, User ...$crew): Crew
{
    test()->actingAs($captain)->post('/crew', ['name' => 'Home']);
    $home = Crew::latest('id')->first();

    foreach ($crew as $user) {
        $home->members()->create(['user_id' => $user->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
    }

    return $home;
}

describe('access', function () {
    it('is behind the crews flag', function () {
        $this->actingAs(User::factory()->create())->get('/crew')->assertNotFound();
        $this->actingAs(sailor())->get('/crew')->assertOk();
    });

    it('404s a crew you are not aboard, and 403s captain-only actions for crew', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs(sailor())->get("/crew/{$home->id}")->assertNotFound();
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertOk();
        $this->actingAs($mate)->post("/crew/{$home->id}/invites", ['email' => 'x@example.com', 'role' => 'crew'])->assertForbidden();
        $this->actingAs($mate)->patch("/crew/{$home->id}", ['name' => 'Mine now'])->assertForbidden();
    });
});

describe('starting a crew', function () {
    it('makes you its captain, audited', function () {
        $me = sailor();

        $this->actingAs($me)->post('/crew', ['name' => '  Home '])->assertRedirect();

        $home = Crew::sole();
        expect($home->name)->toBe('Home')
            ->and($home->membershipOf($me)->role)->toBe(CrewMember::CAPTAIN)
            ->and(AuditLog::where('action', 'crew.created')->where('subject_id', $home->id)->exists())->toBeTrue();
    });

    it('lists only your crews', function () {
        $me = sailor();
        crewOf($me);
        crewOf(sailor());

        $this->actingAs($me)->get('/crew')->assertInertia(fn (Assert $page) => $page
            ->component('crew/index')
            ->has('crews', 1)
            ->where('crews.0.role', 'captain')
            ->where('crews.0.crew_count', 1));
    });
});

describe('invites', function () {
    it('says the same thing whether or not the email has an account', function () {
        $captain = sailor();
        $home = crewOf($captain);
        sailor(['email' => 'sam@example.com']);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'Sam@Example.com', 'role' => 'crew'])
            ->assertSessionHas('status', "Invited sam@example.com — we've emailed them a link to join.");
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'nobody@example.com', 'role' => 'crew'])
            ->assertSessionHas('status', "Invited nobody@example.com — we've emailed them a link to join.");

        expect(CrewInvite::count())->toBe(2);
    });

    it('is accepted by the account with that email, joining with the offered role', function () {
        $captain = sailor(['name' => 'Charlie']);
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'captain']);
        $invite = CrewInvite::sole();

        $this->actingAs($sam)->get('/crew')->assertInertia(fn (Assert $page) => $page
            ->has('invites', 1)
            ->where('invites.0.crew', 'Home')
            ->where('invites.0.invited_by', 'Charlie'));

        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertRedirect("/crew/{$home->id}");

        expect($home->membershipOf($sam)->role)->toBe(CrewMember::CAPTAIN)
            ->and($invite->fresh()->accepted_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'crew_invite.accepted')->where('actor_id', $sam->id)->exists())->toBeTrue();
    });

    it("can't be accepted by anyone else, or once expired, withdrawn or declined", function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);
        $invite = CrewInvite::sole();

        $this->actingAs(sailor())->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        $invite->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        $invite->update(['expires_at' => now()->addDay()]);
        $this->actingAs($captain)->delete("/crew/{$home->id}/invites/{$invite->id}")->assertRedirect();
        $this->actingAs($sam)->post("/crew/invites/{$invite->id}/accept")->assertNotFound();

        expect($home->membershipOf($sam))->toBeNull();
    });

    it('replaces an open invite to the same address, and refuses someone already aboard', function () {
        $captain = sailor(['email' => 'cap@example.com']);
        $home = crewOf($captain);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'captain']);

        expect(CrewInvite::query()->pending()->sole()->role)->toBe('captain');

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'CAP@example.com', 'role' => 'crew'])
            ->assertSessionHasErrors('email');
    });

    it('can be declined', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);

        $this->actingAs($sam)->post('/crew/invites/'.CrewInvite::sole()->id.'/decline')->assertRedirect();

        expect(CrewInvite::sole()->declined_at)->not->toBeNull()
            ->and($home->membershipOf($sam))->toBeNull();
    });
});

/** Invite $email to $crew as $captain and return the emailed link. */
function inviteLink(User $captain, Crew $crew, string $email, string $role = 'crew'): string
{
    Mail::fake();
    test()->actingAs($captain)->post("/crew/{$crew->id}/invites", ['email' => $email, 'role' => $role]);

    $url = null;
    Mail::assertQueued(CrewInviteMail::class, function (CrewInviteMail $mail) use ($email, &$url) {
        $url = $mail->url;

        return $mail->hasTo($email);
    });

    // Back to a stranger's browser: the link is opened from an inbox.
    auth()->logout();
    test()->flushSession();

    return $url;
}

describe('invite links (the invite is the signup)', function () {
    it('emails a link to the invited address, worded the same with or without an account', function () {
        $captain = sailor(['name' => 'Charlie']);
        $home = crewOf($captain);
        sailor(['email' => 'sam@example.com']);
        Mail::fake();

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'sam@example.com', 'role' => 'crew']);
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'new@example.com', 'role' => 'captain']);

        Mail::assertQueued(CrewInviteMail::class, 2);
        Mail::assertQueued(CrewInviteMail::class, fn (CrewInviteMail $mail) => $mail->hasTo('new@example.com')
            && $mail->invitedBy === 'Charlie' && $mail->crewName === 'Home' && $mail->role === 'captain');

        $rendered = (new CrewInviteMail('Home', 'Charlie', 'crew', 'https://example.com/x'))->render();
        expect(strip_tags($rendered))->toContain('Charlie has invited you to join Home on Offing as crew.')
            ->and($rendered)->toContain('https://example.com/x')
            ->and(CrewInvite::whereNull('token_hash')->exists())->toBeFalse();
    });

    it('signs a newcomer up with just a name, verified and signed in, aboard with the offered role', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $url = inviteLink($captain, $home, 'new@example.com', 'captain');

        $this->get($url)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->component('crew/join')
                ->where('state', 'new')
                ->where('crew', 'Home')
                ->where('email', 'new@example.com'));

        // Opening the link changes nothing.
        expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
            ->and(CrewInvite::sole()->accepted_at)->toBeNull();

        $this->post($url, ['answer' => 'accept', 'name' => ' Sam ', 'timezone' => 'America/New_York'])
            ->assertRedirect(route('passkeys.setup'))
            // Crews is admin-only, so the passkey prompt continues to the dashboard.
            ->assertSessionHas('url.intended', '/dashboard');

        $sam = User::where('email', 'new@example.com')->sole();
        $this->assertAuthenticatedAs($sam);
        expect($sam->name)->toBe('Sam')
            ->and($sam->timezone)->toBe('America/New_York')
            ->and($sam->email_verified_at)->not->toBeNull()
            ->and($home->membershipOf($sam)->role)->toBe(CrewMember::CAPTAIN)
            ->and(CrewInvite::sole()->accepted_at)->not->toBeNull()
            ->and(AuditLog::where('action', 'user.created')->where('subject_id', $sam->id)->sole()->meta)->toBe(['via' => 'crew_invite'])
            ->and(AuditLog::where('action', 'crew_invite.accepted')->where('actor_id', $sam->id)->sole()->meta['new_account'])->toBeTrue();
    });

    it('skips the passkey prompt when dismissed, and falls back on an odd timezone', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $url = inviteLink($captain, $home, 'new@example.com');

        $this->withCookie(PasskeySetupController::DISMISS_COOKIE, '1')
            ->post($url, ['answer' => 'accept', 'name' => 'Sam', 'timezone' => 'Mars/Olympus'])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Welcome aboard Home.');

        // Crews is admin-only, so the dashboard says why Home isn't showing.
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('crewsInPreview', ['Home']));
        $this->actingAs($captain)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('crewsInPreview', []));

        expect(User::where('email', 'new@example.com')->sole()->timezone)->toBe(User::DEFAULT_TIMEZONE);
    });

    it('needs a name to sign up', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $url = inviteLink($captain, $home, 'new@example.com');

        $this->post($url, ['answer' => 'accept', 'name' => ''])->assertSessionHasErrors('name');

        expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
            ->and(CrewInvite::sole()->accepted_at)->toBeNull();
    });

    it('sends an existing account to sign in, then back to answer', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $url = inviteLink($captain, $home, 'sam@example.com');

        $this->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('state', 'sign_in'))
            ->assertSessionHas('url.intended', $url);

        // Never a sign-in link: accepting while signed out goes to sign in.
        $this->post($url, ['answer' => 'accept', 'name' => 'Mallory'])->assertRedirect(route('login'));
        $this->assertGuest();
        expect($home->membershipOf($sam))->toBeNull()
            ->and($sam->fresh()->name)->not->toBe('Mallory');

        $this->actingAs($sam)->get($url)->assertInertia(fn (Assert $page) => $page->where('state', 'answer'));
        $this->actingAs($sam)->post($url, ['answer' => 'accept'])
            ->assertRedirect("/crew/{$home->id}")
            ->assertSessionHas('status', 'Welcome aboard Home.');

        expect($home->membershipOf($sam)->role)->toBe(CrewMember::CREW)
            ->and(AuditLog::where('action', 'crew_invite.accepted')->where('actor_id', $sam->id)->sole()->meta['via'])->toBe('link');
    });

    it("won't answer for someone else signed in on this browser", function () {
        $captain = sailor();
        $home = crewOf($captain);
        $url = inviteLink($captain, $home, 'new@example.com');
        $other = sailor(['email' => 'other@example.com']);

        $this->actingAs($other)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('state', 'other')
            ->where('signedInAs', 'other@example.com'));

        $this->actingAs($other)->post($url, ['answer' => 'accept', 'name' => 'Sam'])->assertRedirect($url);
        $this->actingAs($other)->post($url, ['answer' => 'decline'])->assertRedirect($url);
        expect($home->membershipOf($other))->toBeNull()
            ->and(CrewInvite::sole()->isPending())->toBeTrue()
            ->and(User::where('email', 'new@example.com')->exists())->toBeFalse();

        $this->actingAs($other)->post($url, ['answer' => 'switch'])->assertRedirect($url);
        $this->assertGuest();
    });

    it('can be declined without an account', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $url = inviteLink($captain, $home, 'new@example.com');

        $this->post($url, ['answer' => 'decline'])->assertRedirect(route('login'));

        expect(CrewInvite::sole()->declined_at)->not->toBeNull()
            ->and(User::where('email', 'new@example.com')->exists())->toBeFalse()
            ->and(AuditLog::where('action', 'crew_invite.declined')->whereNull('actor_id')->exists())->toBeTrue();
    });

    it('works once, and not once withdrawn, replaced, expired or tampered with', function () {
        $captain = sailor();
        $home = crewOf($captain);

        $used = inviteLink($captain, $home, 'a@example.com');
        $this->post($used, ['answer' => 'accept', 'name' => 'A'])->assertRedirect();
        auth()->logout();
        $this->get($used)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->post($used, ['answer' => 'accept', 'name' => 'A again'])->assertRedirect(route('login'));

        $replaced = inviteLink($captain, $home, 'b@example.com');
        inviteLink($captain, $home, 'b@example.com');
        $this->get($replaced)->assertRedirect(route('login'));

        $withdrawn = inviteLink($captain, $home, 'c@example.com');
        $this->actingAs($captain)->delete("/crew/{$home->id}/invites/".CrewInvite::where('email', 'c@example.com')->sole()->id);
        auth()->logout();
        $this->get($withdrawn)->assertRedirect(route('login'));

        $expired = inviteLink($captain, $home, 'd@example.com');
        $this->travel(CrewInvite::EXPIRES_AFTER_DAYS + 1)->days();
        $this->get($expired)->assertForbidden(); // the signature has run out too
        $this->travelBack();

        $tampered = preg_replace('#/join/(\d+)/(\w+)#', '/join/$1/'.str_repeat('x', 64), inviteLink($captain, $home, 'e@example.com'));
        $this->get($tampered)->assertForbidden();

        expect(User::whereIn('email', ['b@example.com', 'c@example.com', 'd@example.com', 'e@example.com'])->exists())->toBeFalse()
            ->and(AuditLog::where('action', 'crew_invite.link_rejected')->count())->toBeGreaterThanOrEqual(4);
    });

    it('answered in-app, the link is spent too', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com', 'email_verified_at' => now()]);
        $url = inviteLink($captain, $home, 'sam@example.com');

        $this->actingAs($sam)->post('/crew/invites/'.CrewInvite::sole()->id.'/accept')->assertRedirect();
        $this->actingAs($sam)->post($url, ['answer' => 'decline'])->assertRedirect('/dashboard');

        expect(CrewInvite::sole()->declined_at)->toBeNull()
            ->and($home->membershipOf($sam))->not->toBeNull();
    });

    it('tells captains when a crewmate is missing a preview feature', function () {
        $captain = sailor();
        Feature::for($captain)->activate(Shipshape::class);
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $newcomer = User::factory()->create();
        $home->members()->create(['user_id' => $newcomer->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);

        $missing = fn (Assert $page) => $page->where('members.0.missing_features', [])
            ->where('members.1.missing_features', ['Shipshape'])
            ->where('members.2.missing_features', ['Crews', 'Shipshape']);

        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia($missing);
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page->where('members.2.missing_features', []));
    });
});

/** Make a copied invite link for $name and return it. */
function copiedLink(User $captain, Crew $crew, string $name = 'Sam', array $extra = []): string
{
    $response = test()->actingAs($captain)->post("/crew/{$crew->id}/invites", ['delivery' => 'link', 'name' => $name, 'role' => 'crew', ...$extra]);
    $url = $response->getSession()->get('inviteUrl');

    auth()->logout();
    test()->flushSession();

    return $url;
}

/** As a stranger with a copied link: ask to join as $email, return the emailed confirm link. */
function confirmLink(string $link, string $email, string $name = 'Sam'): string
{
    Mail::fake();
    test()->post($link, ['answer' => 'accept', 'name' => $name, 'email' => $email, 'timezone' => 'Europe/Paris']);

    $url = null;
    Mail::assertQueued(CrewJoinConfirmMail::class, function (CrewJoinConfirmMail $mail) use ($email, &$url) {
        $url = $mail->url;

        return $mail->hasTo(strtolower($email));
    });

    return $url;
}

describe('copied invite links', function () {
    it('are shown to the captain once, never emailed, and need a name', function () {
        $captain = sailor();
        $home = crewOf($captain);
        Mail::fake();

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['delivery' => 'link', 'role' => 'crew', 'member_id' => null])->assertSessionHasErrors('name');

        // Exactly what the form posts: every field, member_id null.
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['delivery' => 'link', 'email' => '', 'name' => 'Sam', 'role' => 'captain', 'member_id' => null])
            ->assertSessionHas('inviteUrl', fn (string $url) => str_contains($url, '/crew/join/'))
            ->assertSessionHas('status', "Here's Sam's link — copy it and send it to them. It works once, for 7 days.");

        Mail::assertNothingQueued();
        $invite = CrewInvite::sole();
        expect($invite->email)->toBeNull()
            ->and($invite->name)->toBe('Sam')
            ->and($invite->isLink())->toBeTrue()
            ->and(AuditLog::where('action', 'crew_invite.sent')->sole()->meta['delivery'])->toBe('link');

        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page
            ->where('invites.0.email', null)
            ->where('invites.0.name', 'Sam'));
    });

    it('sign a newcomer up only once they confirm the email they gave', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $link = copiedLink($captain, $home);

        $this->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'link')->where('name', 'Sam'));

        $confirm = confirmLink($link, 'Sam@Example.com');

        // Nothing yet: whoever opened the link hasn't proved that inbox.
        expect(User::where('email', 'sam@example.com')->exists())->toBeFalse()
            ->and(CrewInvite::sole()->isPending())->toBeTrue()
            ->and(AuditLog::where('action', 'crew_invite.confirm_sent')->sole()->meta['email'])->toBe('sam@example.com');

        $this->get($confirm)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->where('state', 'confirm')->where('email', 'sam@example.com'));
        expect(User::where('email', 'sam@example.com')->exists())->toBeFalse();

        $this->post($confirm)->assertRedirect(route('passkeys.setup'));

        $sam = User::where('email', 'sam@example.com')->sole();
        $this->assertAuthenticatedAs($sam);
        expect($sam->name)->toBe('Sam')
            ->and($sam->timezone)->toBe('Europe/Paris')
            ->and($sam->email_verified_at)->not->toBeNull()
            ->and($home->membershipOf($sam)->role)->toBe(CrewMember::CREW);

        // Spent: neither the link nor its confirm works again.
        auth()->logout();
        $this->post($confirm)->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get($link)->assertRedirect(route('login'));
        expect(User::count())->toBe(2);
    });

    it('sign an existing account in from the confirm email, the same as a magic link', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com', 'name' => 'Samantha', 'email_verified_at' => null]);
        $link = copiedLink($captain, $home);

        $confirm = confirmLink($link, 'sam@example.com', 'Not Sam');
        $this->withCookie(PasskeySetupController::DISMISS_COOKIE, '1')->post($confirm)->assertRedirect("/crew/{$home->id}");

        $this->assertAuthenticatedAs($sam);
        expect($sam->fresh()->name)->toBe('Samantha')
            ->and($sam->fresh()->email_verified_at)->not->toBeNull()
            ->and($home->membershipOf($sam))->not->toBeNull()
            ->and(User::count())->toBe(2);
    });

    it('answers the same whether or not the email has an account, and caps the emails', function () {
        $captain = sailor();
        $home = crewOf($captain);
        sailor(['email' => 'sam@example.com']);
        $link = copiedLink($captain, $home);
        Mail::fake();

        $this->post($link, ['answer' => 'accept', 'name' => 'A', 'email' => 'sam@example.com'])
            ->assertSessionHas('status', "Check sam@example.com — we've sent a link to finish joining Home.");
        $this->post($link, ['answer' => 'accept', 'name' => 'B', 'email' => 'nobody@example.com'])
            ->assertSessionHas('status', "Check nobody@example.com — we've sent a link to finish joining Home.");
        $this->post($link, ['answer' => 'accept', 'name' => 'C', 'email' => 'c@example.com']);
        $this->post($link, ['answer' => 'accept', 'name' => 'D', 'email' => 'd@example.com'])->assertSessionHasErrors('email');

        Mail::assertQueued(CrewJoinConfirmMail::class, 3);
    });

    it("can't have its confirm email retargeted", function () {
        $captain = sailor();
        $home = crewOf($captain);
        $confirm = confirmLink(copiedLink($captain, $home), 'sam@example.com');

        $this->get(str_replace('sam%40example.com', 'mallory%40example.com', $confirm))->assertForbidden();
        // Nor pointed at another of the crew's copied links.
        copiedLink($captain, $home, 'Alex');
        $other = CrewInvite::where('name', 'Alex')->sole();
        $this->get(preg_replace('#/join/\d+/#', "/join/{$other->id}/", $confirm))->assertForbidden();
    });

    it('can only be declined by someone signed in, so passers-by can’t use it up', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $link = copiedLink($captain, $home);

        $this->post($link, ['answer' => 'decline'])->assertSessionHasErrors('email');
        expect(CrewInvite::sole()->isPending())->toBeTrue();

        $sam = sailor();
        $this->actingAs($sam)->post($link, ['answer' => 'decline'])->assertRedirect('/dashboard');
        expect(CrewInvite::sole()->declined_at)->not->toBeNull()
            ->and($home->membershipOf($sam))->toBeNull();
    });

    it('joins whoever is signed in, after saying who that is', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sam = sailor(['email' => 'sam@example.com']);
        $link = copiedLink($captain, $home);

        $this->actingAs($sam)->get($link)->assertInertia(fn (Assert $page) => $page
            ->where('state', 'answer')
            ->where('signedInAs', 'sam@example.com'));
        $this->actingAs($sam)->post($link, ['answer' => 'accept'])->assertRedirect("/crew/{$home->id}");

        expect($home->membershipOf($sam)->role)->toBe(CrewMember::CREW);
    });
});

describe('giving a passenger an account', function () {
    /** A crew with passenger Gimli, and an emailed invite giving Gimli an account. */
    function gimli(User $captain, string $email = 'gimli@example.com'): array
    {
        $home = crewOf($captain);
        test()->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Gimli']);
        $member = $home->activeMembers()->where('role', CrewMember::PASSENGER)->sole();

        Mail::fake();
        test()->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => $email, 'role' => 'crew', 'member_id' => $member->id]);
        $url = null;
        Mail::assertQueued(CrewInviteMail::class, function (CrewInviteMail $mail) use (&$url) {
            $url = $mail->url;

            return $mail->passengerName === 'Gimli';
        });
        auth()->logout();
        test()->flushSession();

        return [$home, $member, $url];
    }

    it('turns the passenger row into the account’s — same row, same history', function () {
        $captain = sailor(['name' => 'Charlie']);
        [$home, $member, $url] = gimli($captain);
        $person = $member->person;

        expect(CrewInvite::sole()->name)->toBe('Gimli')
            ->and(CrewInvite::sole()->crew_member_id)->toBe($member->id);

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('state', 'new')
            ->where('passenger', true)
            ->where('name', 'Gimli'));

        $this->post($url, ['answer' => 'accept', 'name' => 'Gimli Glóin'])->assertRedirect();

        $user = User::where('email', 'gimli@example.com')->sole();
        $member->refresh();
        expect($home->membershipOf($user)->id)->toBe($member->id)
            ->and($member->user_id)->toBe($user->id)
            ->and($member->person_id)->toBeNull()
            ->and($member->role)->toBe(CrewMember::CREW)
            ->and($home->members()->count())->toBe(2)
            // The crew's person is kept, and linked to the account (spec §6).
            ->and($person->fresh()->linked_user_id)->toBe($user->id)
            ->and($person->fresh()->owner->is($home))->toBeTrue();
    });

    it('can be accepted in-app too', function () {
        $captain = sailor();
        $gimli = sailor(['email' => 'gimli@example.com', 'email_verified_at' => now()]);
        [$home, $member] = gimli($captain);

        $this->actingAs($gimli)->get('/crew')->assertInertia(fn (Assert $page) => $page->where('invites.0.passenger', 'Gimli'));
        $this->actingAs($gimli)->post('/crew/invites/'.CrewInvite::sole()->id.'/accept')->assertRedirect("/crew/{$home->id}");

        expect($member->fresh()->user_id)->toBe($gimli->id);
    });

    it('refuses an account that is already aboard, leaving the passenger and invite as they were', function () {
        $captain = sailor();
        $gimli = sailor(['email' => 'gimli@example.com', 'email_verified_at' => now()]);
        [$home, $member, $url] = gimli($captain);
        $home->members()->create(['user_id' => $gimli->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);

        $this->actingAs($gimli)->post($url, ['answer' => 'accept'])->assertSessionHasErrors('email');
        $this->actingAs($gimli)->post('/crew/invites/'.CrewInvite::sole()->id.'/accept')->assertSessionHasErrors('invite');

        expect($member->fresh()->person_id)->not->toBeNull()
            ->and($member->fresh()->user_id)->toBeNull()
            ->and(CrewInvite::sole()->isPending())->toBeTrue();
    });

    it('dies with the passenger, and a fresh one replaces the last', function () {
        $captain = sailor();
        [$home, $member, $url] = gimli($captain);

        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['delivery' => 'link', 'role' => 'captain', 'member_id' => $member->id])
            ->assertSessionHas('inviteUrl');
        expect(CrewInvite::query()->pending()->sole()->only(['email', 'name', 'role']))->toBe(['email' => null, 'name' => 'Gimli', 'role' => 'captain']);

        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$member->id}");
        expect(CrewInvite::query()->pending()->exists())->toBeFalse();

        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    });

    it('is for captains, and only for passengers of that crew', function () {
        $captain = sailor();
        $mate = sailor();
        [$home, $member] = gimli($captain);
        $home->members()->create(['user_id' => $mate->id, 'role' => CrewMember::CREW, 'joined_at' => now()]);
        $mateRow = $home->membershipOf($mate);
        $elsewhere = crewOf(sailor());

        $this->actingAs($mate)->post("/crew/{$home->id}/invites", ['email' => 'x@example.com', 'role' => 'crew', 'member_id' => $member->id])->assertForbidden();
        $this->actingAs($captain)->post("/crew/{$home->id}/invites", ['email' => 'x@example.com', 'role' => 'crew', 'member_id' => $mateRow->id])->assertNotFound();
        $this->actingAs($captain)->post("/crew/{$elsewhere->id}/invites", ['email' => 'x@example.com', 'role' => 'crew', 'member_id' => $member->id])->assertNotFound();
    });
});

describe('passengers', function () {
    it('are crew-owned people any crewmate can add, never in anyone’s own People', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($mate)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie'])->assertRedirect();

        $alfie = Person::sole();
        expect($alfie->owner->is($home))->toBeTrue()
            ->and($captain->people()->count())->toBe(0)
            ->and($mate->people()->count())->toBe(0)
            ->and($home->activeMembers()->where('person_id', $alfie->id)->sole()->role)->toBe(CrewMember::PASSENGER);
    });

    it('copies someone from your own People — name only unless you share the birthday', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sue = Person::factory()->for($captain, 'owner')->birthday(14, 3, 1983)->create(['name' => 'Sue', 'notes' => 'Gift idea: dry robe']);
        $bob = Person::factory()->for($captain, 'owner')->birthday(2, 11)->create(['name' => 'Bob']);

        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['person_id' => $sue->id])->assertSessionHasNoErrors();
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['person_id' => $bob->id, 'share_birthday' => true])->assertSessionHasNoErrors();

        $sueCopy = $home->people()->where('name', 'Sue')->sole();
        $bobCopy = $home->people()->where('name', 'Bob')->sole();

        expect($sueCopy->is($sue))->toBeFalse()
            ->and($sueCopy->notes)->toBeNull()
            ->and($sueCopy->birth_day)->toBeNull()
            ->and($bobCopy->only(['birth_day', 'birth_month']))->toBe(['birth_day' => 2, 'birth_month' => 11])
            ->and($sue->fresh()->owner->is($captain))->toBeTrue()
            ->and($sue->fresh()->notes)->toBe('Gift idea: dry robe')
            ->and(AuditLog::where('action', 'crew.passenger_added')->latest('id')->first()->meta)->toMatchArray(['from_people' => true, 'birthday_shared' => true]);

        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page
            ->has('myPeople', 2)
            ->where('myPeople.0.name', 'Bob')
            ->where('myPeople.0.aboard', true)
            ->where('myPeople.1.has_birthday', true));
    });

    it("lets crew add from their own People, but never from someone else's", function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $theirs = Person::factory()->for($captain, 'owner')->create();
        $mine = Person::factory()->for($mate, 'owner')->create(['name' => 'Gimli']);

        $this->actingAs($mate)->post("/crew/{$home->id}/passengers", ['person_id' => $theirs->id])->assertNotFound();
        $this->actingAs($mate)->post("/crew/{$home->id}/passengers", ['person_id' => $mine->id])->assertSessionHasNoErrors();

        // Each crewmate's picker only ever lists their own People.
        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page
            ->has('myPeople', 1)
            ->where('myPeople.0.id', $theirs->id));
        expect($home->people()->pluck('name')->all())->toBe(['Gimli']);
    });

    it("won't add the same person twice, and re-adds a former passenger instead of copying again", function () {
        $captain = sailor();
        $home = crewOf($captain);
        $sue = Person::factory()->for($captain, 'owner')->create(['name' => 'Sue']);

        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['person_id' => $sue->id]);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['person_id' => $sue->id])->assertSessionHasErrors('person_id');

        $member = $home->activeMembers()->whereNotNull('person_id')->sole();
        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$member->id}");
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['person_id' => $sue->id])->assertSessionHasNoErrors();

        expect($member->fresh()->left_at)->toBeNull()
            ->and($home->people()->count())->toBe(1);
    });

    it('keeps the person when a passenger is removed, and can re-add them', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie']);
        $member = $home->activeMembers()->whereNotNull('person_id')->sole();

        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$member->id}")->assertRedirect();

        expect(Person::where('name', 'Alfie')->exists())->toBeTrue()
            ->and($member->fresh()->left_at)->not->toBeNull();

        $this->actingAs($captain)->get("/crew/{$home->id}")->assertInertia(fn (Assert $page) => $page
            ->has('members', 1)
            ->has('formerPassengers', 1)
            ->where('formerPassengers.0.name', 'Alfie'));

        $this->actingAs($captain)->post("/crew/{$home->id}/members/{$member->id}/readd")->assertRedirect();
        expect($member->fresh()->left_at)->toBeNull();
    });
});

describe('every crew keeps a captain', function () {
    it("won't demote the last captain", function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $mine = $home->membershipOf($captain);
        $theirs = $home->membershipOf($mate);

        // Can't demote yourself via the API either, if you're the last one.
        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$mine->id}", ['role' => 'crew'])->assertSessionHasErrors('role');

        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$theirs->id}", ['role' => 'captain'])->assertSessionHasNoErrors();
        $this->actingAs($captain)->patch("/crew/{$home->id}/members/{$mine->id}", ['role' => 'crew'])->assertSessionHasNoErrors();

        expect($home->captainCount())->toBe(1)
            ->and($theirs->fresh()->isCaptain())->toBeTrue()
            ->and(AuditLog::where('action', 'crew.member_role_changed')->count())->toBe(2);
    });

    it('lets crew leave, but not the last captain or the only one aboard', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($captain)->post("/crew/{$home->id}/leave")->assertSessionHasErrors('leave');

        $this->actingAs($mate)->post("/crew/{$home->id}/leave")->assertRedirect('/crew');
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();

        $this->actingAs($captain)->post("/crew/{$home->id}/leave")->assertSessionHasErrors('leave');
        expect($home->membershipOf($captain))->not->toBeNull();
    });

    it('cuts a removed crewmate off at once', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);

        $this->actingAs($captain)->delete("/crew/{$home->id}/members/{$home->membershipOf($mate)->id}")->assertRedirect();

        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();
        expect(AuditLog::where('action', 'crew.member_removed')->exists())->toBeTrue();
    });
});

describe('deleting a crew', function () {
    it('needs the name typed, then moves its people to the captain', function () {
        $captain = sailor();
        $mate = sailor();
        $home = crewOf($captain, $mate);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Alfie']);
        $this->actingAs($captain)->post("/crew/{$home->id}/passengers", ['name' => 'Jo']);
        $this->actingAs($captain)->delete("/crew/{$home->id}/members/".$home->activeMembers()->whereHas('person', fn ($q) => $q->where('name', 'Jo'))->sole()->id);

        $this->actingAs($captain)->delete("/crew/{$home->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        expect($home->fresh()->trashed())->toBeFalse();

        $this->actingAs($captain)->delete("/crew/{$home->id}", ['confirm' => ' home '])->assertRedirect('/crew');

        expect($home->fresh()->trashed())->toBeTrue()
            ->and($captain->people()->pluck('name')->sort()->values()->all())->toBe(['Alfie', 'Jo'])
            ->and(AuditLog::where('action', 'crew.deleted')->sole()->meta)->toMatchArray(['people_moved' => 2]);

        $this->actingAs($mate)->get('/crew')->assertInertia(fn (Assert $page) => $page->has('crews', 0));
        $this->actingAs($mate)->get("/crew/{$home->id}")->assertNotFound();
    });

    it('is pruned for good after 30 days', function () {
        $captain = sailor();
        $home = crewOf($captain);
        $home->delete();
        // A passenger left behind on a crew deleted some other way goes with it.
        $home->people()->create(['name' => 'Orphan']);

        $this->travel(29)->days();
        $this->artisan('model:prune', ['--model' => [Crew::class]]);
        expect(Crew::withTrashed()->count())->toBe(1);

        $this->travel(2)->days();
        $this->artisan('model:prune', ['--model' => [Crew::class]]);
        expect(Crew::withTrashed()->count())->toBe(0)
            ->and(Person::withTrashed()->where('name', 'Orphan')->exists())->toBeFalse();
    });
});

describe('deleting an account', function () {
    it('takes your own People with it', function () {
        $me = sailor();
        Person::factory()->for($me, 'owner')->create();
        Person::factory()->for($me, 'owner')->create()->delete();
        $other = Person::factory()->create();

        $me->delete();

        expect(Person::withTrashed()->pluck('id')->all())->toBe([$other->id]);
    });

    it('promotes the longest-serving crewmate when the last captain goes', function () {
        $captain = sailor();
        $first = sailor();
        $second = sailor();
        $home = crewOf($captain, $first);
        $home->members()->create(['user_id' => $second->id, 'role' => CrewMember::CREW, 'joined_at' => now()->addMinute()]);

        $captain->delete();

        expect($home->membershipOf($first)->isCaptain())->toBeTrue()
            ->and($home->membershipOf($second)->isCaptain())->toBeFalse()
            ->and(AuditLog::where('action', 'crew.captain_promoted')->exists())->toBeTrue();
    });

    it('sinks a crew nobody else is aboard', function () {
        $captain = sailor();
        $home = crewOf($captain);

        $captain->delete();

        expect($home->fresh()->trashed())->toBeTrue();
    });
});
