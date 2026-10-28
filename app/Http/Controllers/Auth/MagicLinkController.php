<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\MagicLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MagicLinkController extends Controller
{
    /**
     * Show the magic link request page.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Send a magic link to the given email address, if it belongs to a known user.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $user = User::whereRaw('lower(email) = ?', [Str::lower($request->string('email'))])->first();

        if ($user) {
            $signedUrl = MagicLink::issue($user, $request->boolean('remember', true));

            Mail::to($user->email)->queue(new MagicLinkMail($user, $signedUrl));

            AuditLog::record($user, 'auth.magic_link.requested', ip: $request->ip());
        }

        return back()->with('status', "If that's an Offing account, we've sent a magic link — check your email.");
    }

    /**
     * Where the emailed link lands: who it signs in as, and a button. Opening
     * it changes nothing — mail scanners fetch links, and someone sent
     * another account's link (login CSRF) sees whose it is before agreeing.
     */
    public function show(Request $request, MagicLink $magicLink, string $token): SymfonyResponse
    {
        if (! $magicLink->matches(MagicLink::LOGIN, $token) || ! $magicLink->isUsable()) {
            return $this->rejected($request, $magicLink);
        }

        $current = $request->user();

        $response = Inertia::render('auth/magic-link', [
            'email' => $magicLink->user->email,
            // Signed in as someone else here: continuing signs them out.
            'signedInAs' => $current !== null && $current->id !== $magicLink->user_id ? $current->email : null,
            // The signed link itself: the POST is checked the same way.
            'action' => $request->fullUrl(),
        ])->toResponse($request);

        // The URL carries the token: never send it on to fonts or anywhere else.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * The button on that page: claim the link and log its owner in.
     */
    public function consume(Request $request, MagicLink $magicLink, string $token): RedirectResponse
    {
        $user = $magicLink->user;

        // Email-change links are magic links too, but never sign anyone in.
        if (! $magicLink->matches(MagicLink::LOGIN, $token) || ! $magicLink->claim()) {
            return $this->rejected($request, $magicLink);
        }

        if (Auth::check() && Auth::id() !== $user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, remember: $magicLink->remember);

        $request->session()->regenerate();

        AuditLog::record($user, 'auth.login', ip: $request->ip());

        // Spec §15: offer a passkey so next time is Face ID, not email. The
        // intended URL stays in the session for the setup page to continue to.
        if (PasskeySetupController::shouldPrompt($request)) {
            return redirect()->route('passkeys.setup');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function rejected(Request $request, MagicLink $magicLink): RedirectResponse
    {
        AuditLog::record($magicLink->user, 'auth.magic_link.rejected', ip: $request->ip());

        return redirect()->route('login')->withErrors([
            'email' => 'That link has expired or already been used — request a new one.',
        ]);
    }
}
