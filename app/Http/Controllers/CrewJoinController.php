<?php

namespace App\Http\Controllers;

use App\Features\Crews;
use App\Http\Controllers\Auth\PasskeySetupController;
use App\Mail\CrewJoinConfirmMail;
use App\Models\AuditLog;
use App\Models\CrewInvite;
use App\Models\User;
use App\Support\Users\CreateUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where a crew invite's link lands (spec §7/§15): the invite is the
 * signup. Opening it changes nothing — mail scanners open links too — and
 * it shows one of these:
 *
 * An emailed invite (the link proves that inbox, like a magic link):
 *  - answer:  signed in with the invited email — accept or decline.
 *  - other:   signed in as someone else — sign out to continue.
 *  - sign_in: that email has an account — sign in, then come back here.
 *  - new:     no account yet — give a name and you're aboard and signed in.
 *
 * A copied link (proves nothing about any inbox):
 *  - answer:  signed in — join as that account.
 *  - link:    not signed in — sign in, or give a name and email; we email a
 *             link that finishes joining (confirm), so nobody gets an
 *             account for an address they can't open.
 *
 * An existing account still signs in the usual way from an emailed invite:
 * a week-long invite is not a sign-in link for an account with data in it.
 */
class CrewJoinController extends Controller
{
    /** How long the "finish joining" email from a copied link works. */
    public const CONFIRM_MINUTES = 60;

    /** "Finish joining" emails per copied link, per hour. */
    public const CONFIRMS_PER_HOUR = 3;

    public function show(Request $request, CrewInvite $invite, string $token): SymfonyResponse
    {
        if (! $this->usable($invite, $token)) {
            return $this->rejected($request, $invite);
        }

        $state = $this->state($request, $invite);

        if ($state === 'sign_in' || $state === 'link') {
            // Back here after signing in (same browser), to answer.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return $this->page($request, $invite, $state, [
            // Who's signed in, when that's who would join (a copied link) or
            // who'd have to sign out first (someone else's emailed invite).
            'signedInAs' => $state === 'other' || ($state === 'answer' && $invite->isLink()) ? $request->user()?->email : null,
        ]);
    }

    public function store(Request $request, CrewInvite $invite, string $token): RedirectResponse
    {
        if (! $this->usable($invite, $token)) {
            return $this->rejected($request, $invite);
        }

        $answer = $request->validate(['answer' => ['required', Rule::in(['accept', 'decline', 'switch'])]])['answer'];
        $state = $this->state($request, $invite);

        if ($answer === 'switch' || $state === 'other') {
            return $this->switchAccount($request, $answer);
        }

        if ($answer === 'decline') {
            return $this->decline($request, $invite);
        }

        return match ($state) {
            'answer' => $this->accept($request, $invite, $request->user()),
            'sign_in' => $this->toSignIn($request, $invite),
            'link' => $this->sendConfirm($request, $invite),
            default => $this->signUp($request, $invite),
        };
    }

    /**
     * The "finish joining" link from a copied invite, opened from the inbox
     * it was sent to. GET shows who it's for and a button; only POST acts.
     */
    public function showConfirm(Request $request, CrewInvite $invite): SymfonyResponse
    {
        if (! $this->confirmable($request, $invite)) {
            return $this->rejected($request, $invite);
        }

        $email = (string) $request->query('email');
        $current = $request->user();

        return $this->page($request, $invite, 'confirm', [
            'email' => $email,
            // Signed in as someone else here: continuing signs them out.
            'signedInAs' => $current !== null && Str::lower($current->email) !== $email ? $current->email : null,
        ]);
    }

    /**
     * The button on that page. The link proves the inbox: an account with
     * that email is signed in (like a magic link); otherwise one is made.
     */
    public function confirm(Request $request, CrewInvite $invite): RedirectResponse
    {
        if (! $this->confirmable($request, $invite)) {
            return $this->rejected($request, $invite);
        }

        $email = (string) $request->query('email');
        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing === null) {
            return $this->signUp($request, $invite, $email, (string) $request->query('name'), (string) $request->query('timezone'));
        }

        $result = $invite->acceptFor($existing);

        if ($result !== CrewInvite::ACCEPTED) {
            return $this->notAccepted($request, $invite, $result);
        }

        // This inbox just opened the link, same as a magic link.
        if ($existing->email_verified_at === null) {
            $existing->forceFill(['email_verified_at' => now()])->save();
        }

        $this->signIn($request, $existing);
        AuditLog::record($existing, 'crew_invite.accepted', $invite->crew, ['invite_id' => $invite->id, 'role' => $invite->role, 'via' => 'link'], $request->ip());

        return $this->afterSignIn($request, $existing, $invite);
    }

    /**
     * Shown when a passenger's invite meets an account that's been aboard
     * already: merging the two is a captain's job (spec §7), not built yet.
     */
    public static function alreadyAboard(CrewInvite $invite): string
    {
        return "This account has already been aboard {$invite->crew->name}, so it can't take over {$invite->name}'s place. Ask a captain to invite you back instead.";
    }

    private function accept(Request $request, CrewInvite $invite, User $user): RedirectResponse
    {
        $result = $invite->acceptFor($user);

        if ($result !== CrewInvite::ACCEPTED) {
            return $this->notAccepted($request, $invite, $result);
        }

        // An emailed link went to this inbox, same as a magic link would.
        if ($user->email_verified_at === null && ! $invite->isLink()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        AuditLog::record($user, 'crew_invite.accepted', $invite->crew, ['invite_id' => $invite->id, 'role' => $invite->role, 'via' => 'link'], $request->ip());

        return $this->welcome($user, $invite);
    }

    /**
     * No account with that email: make one from just a name, verified, and
     * signed in — all or nothing, so a lost race leaves no stray account.
     * The email is the invite's, or the one a copied link's confirm proved.
     */
    private function signUp(Request $request, CrewInvite $invite, ?string $email = null, ?string $name = null, ?string $timezone = null): RedirectResponse
    {
        if ($email === null) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'timezone' => ['nullable', 'string'],
            ]);
            [$email, $name, $timezone] = [(string) $invite->email, $data['name'], $data['timezone'] ?? null];
        }

        try {
            $user = DB::transaction(function () use ($request, $invite, $email, $name, $timezone) {
                $user = CreateUser::create(['email' => $email, 'name' => $name, 'timezone' => $this->timezone($timezone)], null, 'crew_invite', $request->ip());
                $user->forceFill(['email_verified_at' => now()])->save();

                if ($invite->acceptFor($user) !== CrewInvite::ACCEPTED) {
                    throw new RuntimeException('Invite already answered.');
                }

                return $user;
            });
        } catch (RuntimeException) {
            return $this->rejected($request, $invite);
        }

        $this->signIn($request, $user);
        AuditLog::record($user, 'crew_invite.accepted', $invite->crew, ['invite_id' => $invite->id, 'role' => $invite->role, 'via' => 'link', 'new_account' => true], $request->ip());

        return $this->afterSignIn($request, $user, $invite);
    }

    /**
     * A copied link, not signed in: email whoever opened it a link that
     * finishes joining. Worded and answered the same whether or not that
     * address has an account, so a link can't be used to probe for users.
     */
    private function sendConfirm(Request $request, CrewInvite $invite): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'timezone' => ['nullable', 'string'],
        ]);
        $email = Str::lower(trim($data['email']));

        // A link in a group chat mustn't become a way to email anyone.
        if (RateLimiter::tooManyAttempts($key = 'crew-join-confirm:'.$invite->id, self::CONFIRMS_PER_HOUR)) {
            return back()->withErrors(['email' => "We've sent a few of these already — check your inbox, or ask for a fresh invite."]);
        }

        RateLimiter::hit($key, 3600);

        $url = URL::temporarySignedRoute('crew.join.confirm', now()->addMinutes(self::CONFIRM_MINUTES), [
            'invite' => $invite->id,
            'email' => $email,
            'name' => trim($data['name']),
            'timezone' => $data['timezone'] ?? '',
        ]);

        Mail::to($email)->queue(new CrewJoinConfirmMail($invite->crew->name, $url, self::CONFIRM_MINUTES));
        AuditLog::record($request->user(), 'crew_invite.confirm_sent', $invite->crew, ['invite_id' => $invite->id, 'email' => $email], $request->ip());

        return back()->with('status', "Check {$email} — we've sent a link to finish joining {$invite->crew->name}.");
    }

    /**
     * Anyone holding the link may say no, since it went to them — unless
     * they're signed in as someone else, which store() turns away.
     */
    private function decline(Request $request, CrewInvite $invite): RedirectResponse
    {
        if (! $invite->claim('declined_at')) {
            return $this->rejected($request, $invite);
        }

        $user = $request->user();
        AuditLog::record($user, 'crew_invite.declined', $invite->crew, ['invite_id' => $invite->id, 'via' => 'link'], $request->ip());

        $message = "No problem — you've declined the invite to {$invite->crew->name}.";

        return $user !== null
            ? to_route('dashboard')->with('status', $message)
            : to_route('login')->with('status', $message);
    }

    private function toSignIn(Request $request, CrewInvite $invite): RedirectResponse
    {
        $request->session()->put('url.intended', $request->fullUrl());

        return to_route('login')->with('status', "Sign in as {$invite->email} to answer the invite to {$invite->crew->name}.");
    }

    /**
     * Signed in as someone else: sign them out and reopen the link. Never
     * answers on the wrong account's behalf, whatever was asked.
     */
    private function switchAccount(Request $request, string $answer): RedirectResponse
    {
        if ($answer === 'switch' && Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect($request->fullUrl());
    }

    /** @param  CrewInvite::GONE|CrewInvite::ALREADY_ABOARD  $result */
    private function notAccepted(Request $request, CrewInvite $invite, string $result): RedirectResponse
    {
        if ($result === CrewInvite::GONE) {
            return $this->rejected($request, $invite);
        }

        return redirect(Auth::check() ? route('dashboard') : route('login'))->withErrors(['email' => self::alreadyAboard($invite)]);
    }

    private function signIn(Request $request, User $user): void
    {
        if (Auth::check() && Auth::id() !== $user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        AuditLog::record($user, 'auth.login', meta: ['via' => 'crew_invite'], ip: $request->ip());
    }

    /** Spec §15: a passkey next, so next time is Face ID, not email. */
    private function afterSignIn(Request $request, User $user, CrewInvite $invite): RedirectResponse
    {
        if (PasskeySetupController::shouldPrompt($request)) {
            $request->session()->put('url.intended', $this->destination($user, $invite));

            return redirect()->route('passkeys.setup');
        }

        return $this->welcome($user, $invite);
    }

    private function welcome(User $user, CrewInvite $invite): RedirectResponse
    {
        // Without Crews yet, the dashboard says why the crew isn't showing.
        return redirect($this->destination($user, $invite))->with('status', "Welcome aboard {$invite->crew->name}.");
    }

    private function destination(User $user, CrewInvite $invite): string
    {
        return Feature::for($user)->active(Crews::class)
            ? route('crew.show', $invite->crew, absolute: false)
            : route('dashboard', absolute: false);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(Request $request, CrewInvite $invite, string $state, array $props): SymfonyResponse
    {
        $response = Inertia::render('crew/join', [
            'crew' => $invite->crew->name,
            'invitedBy' => $invite->invitedBy?->name,
            'role' => $invite->role,
            'email' => $invite->email,
            // Who the captain made it for: a passenger's name, or a copied link's.
            'name' => $invite->name,
            'passenger' => $invite->crew_member_id !== null,
            'state' => $state,
            'signedInAs' => null,
            // The signed link itself: the POST is checked the same way.
            'action' => $request->fullUrl(),
            ...$props,
        ])->toResponse($request);

        // The URL carries the token: never send it on to fonts or anywhere else.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * @return 'answer'|'other'|'sign_in'|'new'|'link'
     */
    private function state(Request $request, CrewInvite $invite): string
    {
        $current = $request->user();

        if ($invite->isLink()) {
            return $current !== null ? 'answer' : 'link';
        }

        if ($current !== null) {
            return Str::lower($current->email) === $invite->email ? 'answer' : 'other';
        }

        return User::whereRaw('lower(email) = ?', [$invite->email])->exists() ? 'sign_in' : 'new';
    }

    /** Pre-detected by the browser; an odd one falls back to the default. */
    private function timezone(?string $timezone): ?string
    {
        return in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $timezone : null;
    }

    private function usable(CrewInvite $invite, string $token): bool
    {
        return $invite->matches($token) && $invite->isPending() && $invite->crew !== null;
    }

    private function confirmable(Request $request, CrewInvite $invite): bool
    {
        return $invite->isLink() && $invite->isPending() && $invite->crew !== null
            && filter_var($request->query('email'), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function rejected(Request $request, CrewInvite $invite): RedirectResponse
    {
        AuditLog::record($request->user(), 'crew_invite.link_rejected', $invite, ['crew_id' => $invite->crew_id], $request->ip());

        return redirect(Auth::check() ? route('dashboard') : route('login'))->withErrors([
            'email' => 'That invite has expired or has already been answered — ask whoever sent it for a fresh one.',
        ]);
    }
}
