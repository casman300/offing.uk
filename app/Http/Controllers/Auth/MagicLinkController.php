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
     * Consume a magic link and log the owning user in.
     */
    public function consume(Request $request, MagicLink $magicLink, string $token): RedirectResponse
    {
        $user = $magicLink->user;

        // Email-change links are magic links too, but never sign anyone in.
        if (! $magicLink->matches(MagicLink::LOGIN, $token) || ! $magicLink->claim()) {
            AuditLog::record($user, 'auth.magic_link.rejected', ip: $request->ip());

            return redirect()->route('login')->withErrors([
                'email' => 'That link has expired or already been used — request a new one.',
            ]);
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
}
