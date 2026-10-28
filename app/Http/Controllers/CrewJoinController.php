<?php

namespace App\Http\Controllers;

use App\Features\Crews;
use App\Http\Controllers\Auth\PasskeySetupController;
use App\Models\AuditLog;
use App\Models\CrewInvite;
use App\Models\User;
use App\Support\Users\CreateUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Where a crew invite's emailed link lands (spec §7/§15): the invite is
 * the signup. Opening it changes nothing — mail scanners open links too —
 * and it shows one of four things:
 *
 *  - answer:  signed in with the invited email — accept or decline.
 *  - other:   signed in as someone else — sign out to continue.
 *  - sign_in: that email has an account — sign in, then come back here.
 *  - new:     no account yet — give a name and you're aboard and signed in.
 *
 * The link proves the inbox, like a magic link, so a newcomer's account
 * starts verified. An existing account still signs in the usual way: a
 * week-long invite is not a sign-in link for an account with data in it.
 */
class CrewJoinController extends Controller
{
    public function show(Request $request, CrewInvite $invite, string $token): SymfonyResponse
    {
        if (! $this->usable($invite, $token)) {
            return $this->rejected($request, $invite);
        }

        $state = $this->state($request, $invite);

        if ($state === 'sign_in') {
            // Back here after signing in (same browser), to answer.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $response = Inertia::render('crew/join', [
            'crew' => $invite->crew->name,
            'invitedBy' => $invite->invitedBy?->name,
            'role' => $invite->role,
            'email' => $invite->email,
            'state' => $state,
            'signedInAs' => $state === 'other' ? $request->user()?->email : null,
            // The signed link itself: the POST is checked the same way.
            'action' => $request->fullUrl(),
        ])->toResponse($request);

        // The URL carries the token: never send it on to fonts or anywhere else.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
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
            default => $this->signUp($request, $invite),
        };
    }

    private function accept(Request $request, CrewInvite $invite, User $user): RedirectResponse
    {
        if (! $invite->acceptFor($user)) {
            return $this->rejected($request, $invite);
        }

        // The link went to this inbox, same as a magic link would.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        AuditLog::record($user, 'crew_invite.accepted', $invite->crew, ['invite_id' => $invite->id, 'role' => $invite->role, 'via' => 'link'], $request->ip());

        return $this->welcome($user, $invite);
    }

    /**
     * No account with that email: make one from just a name, verified, and
     * signed in — all or nothing, so a lost race leaves no stray account.
     */
    private function signUp(Request $request, CrewInvite $invite): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string'],
        ]);

        // Pre-detected by the browser; an odd one falls back to the default
        // rather than showing an error nobody can fix.
        $timezone = in_array($data['timezone'] ?? null, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true) ? $data['timezone'] : null;

        try {
            $user = DB::transaction(function () use ($request, $invite, $data, $timezone) {
                $user = CreateUser::create(['email' => $invite->email, 'name' => $data['name'], 'timezone' => $timezone], null, 'crew_invite', $request->ip());
                $user->forceFill(['email_verified_at' => now()])->save();

                if (! $invite->acceptFor($user)) {
                    throw new RuntimeException('Invite already answered.');
                }

                return $user;
            });
        } catch (RuntimeException) {
            return $this->rejected($request, $invite);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        AuditLog::record($user, 'auth.login', meta: ['via' => 'crew_invite'], ip: $request->ip());
        AuditLog::record($user, 'crew_invite.accepted', $invite->crew, ['invite_id' => $invite->id, 'role' => $invite->role, 'via' => 'link', 'new_account' => true], $request->ip());

        // Spec §15: a passkey next, so next time is Face ID, not email.
        if (PasskeySetupController::shouldPrompt($request)) {
            $request->session()->put('url.intended', $this->destination($user, $invite));

            return redirect()->route('passkeys.setup');
        }

        return $this->welcome($user, $invite);
    }

    /**
     * Anyone holding the link may say no, since it went to that inbox —
     * unless they're signed in as someone else, which store() turns away.
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
     * @return 'answer'|'other'|'sign_in'|'new'
     */
    private function state(Request $request, CrewInvite $invite): string
    {
        $current = $request->user();

        if ($current !== null) {
            return Str::lower($current->email) === $invite->email ? 'answer' : 'other';
        }

        return User::whereRaw('lower(email) = ?', [$invite->email])->exists() ? 'sign_in' : 'new';
    }

    private function usable(CrewInvite $invite, string $token): bool
    {
        return $invite->matches($token) && $invite->isPending() && $invite->crew !== null;
    }

    private function rejected(Request $request, CrewInvite $invite): RedirectResponse
    {
        AuditLog::record($request->user(), 'crew_invite.link_rejected', $invite, ['crew_id' => $invite->crew_id], $request->ip());

        return redirect(Auth::check() ? route('dashboard') : route('login'))->withErrors([
            'email' => 'That invite has expired or has already been answered — ask whoever sent it for a fresh one.',
        ]);
    }
}
