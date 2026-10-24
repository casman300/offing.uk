<?php

use App\Features\BirthdayEngine;
use App\Features\Features;
use App\Features\Reminders;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['offing.admin_emails' => ['boss@example.com']]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com', 'name' => 'Charlie']);
    Mail::fake();
});

describe('access', function () {
    it('404s for non-admins on every route', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/users')->assertNotFound();
        $this->actingAs($user)->post('/admin/users', ['email' => 'new@example.com'])->assertNotFound();
        $this->actingAs($user)->post("/admin/users/{$user->id}/sign-in-link")->assertNotFound();
        $this->actingAs($user)->post("/admin/users/{$user->id}/beta", ['beta' => true])->assertNotFound();

        expect(User::where('email', 'new@example.com')->exists())->toBeFalse()
            ->and($user->fresh()->is_beta_tester)->toBeFalse();
        Mail::assertNothingQueued();
    });

    it('sends guests to the login page', function () {
        $this->get('/admin/users')->assertRedirect('/login');
    });
});

describe('the list', function () {
    it('shows sign-in, passkey and flag state without pinning anyone', function () {
        $sam = User::factory()->create(['email' => 'sam@example.com', 'name' => 'Sam']);
        // Only the count matters here, so a bare row rather than a WebAuthn ceremony.
        DB::table('passkeys')->insert(['user_id' => $sam->id, 'name' => 'iPhone', 'credential_id' => 'abc', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        Feature::for($sam)->activate(Reminders::class);
        AuditLog::record($sam, 'auth.login');
        $before = DB::table('features')->count();

        $this->actingAs($this->boss)->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users')
                ->where('defaultTimezone', 'Europe/London')
                ->has('flags', count(Features::all()))
                ->has('users', 2)
                ->where('users.0.email', 'sam@example.com')
                ->where('users.0.passkeys', 1)
                ->where('users.0.last_login_at', fn ($at) => $at !== null)
                ->where('users.0.flags_on', ['reminders'])
                ->where('users.1.email', 'boss@example.com')
                ->where('users.1.is_admin', true)
                ->where('users.1.last_login_at', null)
                ->where('users.1.flags_on', fn ($on) => collect($on)->contains('birthday-engine')));

        expect(DB::table('features')->where('scope', '!=', Feature::serializeScope($this->boss))->count())->toBe($before);
    });

    it('searches by email or name', function () {
        User::factory()->create(['email' => 'sam@example.com', 'name' => 'Sam Partner']);
        User::factory()->create(['email' => 'tom@example.com', 'name' => 'Tom']);

        $this->actingAs($this->boss)->get('/admin/users?q=partner')
            ->assertInertia(fn (Assert $page) => $page->has('users', 1)->where('users.0.email', 'sam@example.com'));
    });
});

describe('adding a user', function () {
    it('creates them with flags on and a welcome link, all audited', function () {
        $this->actingAs($this->boss)->post('/admin/users', [
            'email' => '  Sam@Example.com ',
            'name' => 'Sam',
            'timezone' => 'Europe/Paris',
            'flags' => ['birthday-engine', 'reminders'],
            'send_link' => true,
        ])->assertRedirect()->assertSessionHas('status');

        $sam = User::where('email', 'sam@example.com')->sole();
        expect($sam->name)->toBe('Sam')
            ->and($sam->timezone)->toBe('Europe/Paris');

        Feature::flushCache();
        expect(Feature::for($sam)->active(BirthdayEngine::class))->toBeTrue()
            ->and(Feature::for($sam)->active(Reminders::class))->toBeTrue();

        Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('sam@example.com')
            && $mail->welcome
            && $mail->invitedBy === 'Charlie');

        $actions = AuditLog::where('subject_id', $sam->id)->where('actor_id', $this->boss->id)->pluck('action');
        expect($actions->all())->toEqualCanonicalizing(['user.created', 'feature.activated', 'feature.activated', 'auth.magic_link.sent'])
            ->and(AuditLog::where('action', 'user.created')->sole()->meta)->toBe(['via' => 'admin']);
    });

    it('defaults the name and timezone, and sends nothing unless asked', function () {
        $this->actingAs($this->boss)->post('/admin/users', ['email' => 'alex@example.com', 'send_link' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'alex@example.com', 'name' => 'alex', 'timezone' => 'Europe/London']);
        Mail::assertNothingQueued();
    });

    it('rejects a taken email, a bad timezone or an unknown flag, creating nothing', function (array $input, string $field) {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->boss)->post('/admin/users', $input)->assertSessionHasErrors($field);

        expect(User::count())->toBe(2)
            ->and(AuditLog::where('action', 'user.created')->exists())->toBeFalse();
        Mail::assertNothingQueued();
    })->with([
        'taken email' => [['email' => 'Taken@example.com', 'send_link' => true], 'email'],
        'not an email' => [['email' => 'nope'], 'email'],
        'bad timezone' => [['email' => 'new@example.com', 'timezone' => 'Mars/Olympus'], 'timezone'],
        'unknown flag' => [['email' => 'new@example.com', 'flags' => ['time-travel']], 'flags.0'],
    ]);
});

describe('beta testers', function () {
    it('ticks and unticks a beta tester, audited once per change', function () {
        $user = User::factory()->create();

        $this->actingAs($this->boss)->post("/admin/users/{$user->id}/beta", ['beta' => true])
            ->assertRedirect()->assertSessionHas('status', "{$user->email} is now a beta tester.");
        $this->actingAs($this->boss)->post("/admin/users/{$user->id}/beta", ['beta' => true]);
        expect($user->fresh()->is_beta_tester)->toBeTrue();

        $this->actingAs($this->boss)->post("/admin/users/{$user->id}/beta", ['beta' => false]);
        expect($user->fresh()->is_beta_tester)->toBeFalse()
            ->and(AuditLog::where('subject_id', $user->id)->orderBy('id')->pluck('action')->all())->toBe(['user.beta_added', 'user.beta_removed']);
    });

    it('shows beta testers in the list', function () {
        User::factory()->create(['email' => 'beta@example.com', 'is_beta_tester' => true]);

        $this->actingAs($this->boss)->get('/admin/users?q=beta')
            ->assertInertia(fn (Assert $page) => $page->where('users.0.is_beta_tester', true));
    });

    it('validates the value', function () {
        $user = User::factory()->create();

        $this->actingAs($this->boss)->post("/admin/users/{$user->id}/beta", [])->assertSessionHasErrors('beta');
    });
});

describe('sign-in links', function () {
    it('sends the ordinary wording to someone who has signed in before', function () {
        $sam = User::factory()->create(['email' => 'sam@example.com']);

        $this->actingAs($this->boss)->post("/admin/users/{$sam->id}/sign-in-link")
            ->assertRedirect()->assertSessionHas('status');

        Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->hasTo('sam@example.com') && ! $mail->welcome);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.magic_link.sent', 'actor_id' => $this->boss->id, 'subject_id' => $sam->id]);
    });

    it('works: the emailed link signs them in', function () {
        $this->actingAs($this->boss)->post('/admin/users', ['email' => 'sam@example.com', 'send_link' => true]);
        auth()->logout();

        $url = null;
        Mail::assertQueued(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url) {
            $url = $mail->url;

            return true;
        });

        $this->get($url)->assertRedirect();

        $sam = User::where('email', 'sam@example.com')->sole();
        $this->assertAuthenticatedAs($sam);
        expect($sam->fresh()->email_verified_at)->not->toBeNull();
    });
});

describe('the email itself', function () {
    it('renders the welcome wording', function () {
        $sam = User::factory()->make(['name' => 'Sam']);
        $html = (new MagicLinkMail($sam, 'https://offing.test/link', welcome: true, invitedBy: 'Charlie'))->render();

        expect($html)->toContain('Charlie has set up an Offing account for you')
            ->toContain('https://offing.test/link')
            ->not->toContain('just ignore this email')
            ->and((new MagicLinkMail($sam, 'x', welcome: true))->build()->subject)->toBe("You're on Offing");
    });

    it('keeps the ordinary wording otherwise', function () {
        $html = (new MagicLinkMail(User::factory()->make(['name' => 'Sam']), 'https://offing.test/link'))->render();

        expect($html)->toContain('no password required')
            ->toContain('just ignore this email')
            ->not->toContain('has set up an Offing account');
    });
});
