<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\PasskeySetupController;
use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertOk();
    }

    public function test_requesting_a_magic_link_for_a_known_email_sends_one(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->post('/login', ['email' => $user->email]);

        $response->assertSessionHas('status');
        $this->assertDatabaseCount('magic_links', 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.magic_link.requested']);
        Mail::assertQueued(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->user->is($user));
    }

    public function test_requesting_a_magic_link_for_an_unknown_email_sends_nothing_but_gives_no_hint(): void
    {
        Mail::fake();

        $response = $this->post('/login', ['email' => 'nobody@example.com']);

        $response->assertSessionHas('status');
        $this->assertDatabaseCount('magic_links', 0);
        Mail::assertNothingSent();
    }

    public function test_a_valid_magic_link_logs_the_user_in(): void
    {
        $user = User::factory()->unverified()->create();
        $token = 'plain-text-token';

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]);

        $response = $this->get($url);

        $response->assertRedirect(route('passkeys.setup'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($magicLink->fresh()->used_at);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.login']);
    }

    public function test_remember_me_defaults_to_a_persistent_login(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $token = 'plain-text-token';

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->get(URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]));

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_unchecking_remember_me_gives_a_session_only_login(): void
    {
        $user = User::factory()->create(['remember_token' => null]);
        $token = 'plain-text-token';

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
            'remember' => false,
        ]);

        $response = $this->withCookie(PasskeySetupController::DISMISS_COOKIE, '1')
            ->get(URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]));

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->remember_token);
    }

    public function test_the_remember_choice_made_at_request_time_is_honoured_at_consume_time(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'remember' => false]);

        $this->assertDatabaseHas('magic_links', ['user_id' => $user->id, 'remember' => false]);
    }

    public function test_a_used_magic_link_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = 'plain-text-token';

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
            'used_at' => now(),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]);

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_expired_magic_link_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = 'plain-text-token';

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]);

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', 'the-real-token'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => 'a-guessed-token']);

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_rejected_consume_attempts_are_audited(): void
    {
        $user = User::factory()->create();

        $magicLink = $user->magicLinks()->create([
            'token_hash' => hash('sha256', 'the-real-token'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => 'a-guessed-token']);

        $this->get($url);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'auth.magic_link.rejected',
            'actor_id' => $user->id,
        ]);
    }

    public function test_a_magic_link_swaps_the_session_on_a_shared_device(): void
    {
        $deviceUser = User::factory()->create();
        $linkOwner = User::factory()->create();
        $token = 'plain-text-token';

        $magicLink = $linkOwner->magicLinks()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(15),
        ]);

        $url = URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $token]);

        $response = $this->actingAs($deviceUser)->withCookie(PasskeySetupController::DISMISS_COOKIE, '1')->get($url);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($linkOwner);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.logout']);
    }
}
