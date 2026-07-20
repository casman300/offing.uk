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
use Illuminate\Support\Facades\URL;
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
            $plainTextToken = Str::random(64);

            $magicLink = $user->magicLinks()->create([
                'token_hash' => hash('sha256', $plainTextToken),
                'expires_at' => now()->addMinutes(15),
            ]);

            Mail::to($user->email)->send(new MagicLinkMail(
                $user,
                URL::signedRoute('login.consume', ['magicLink' => $magicLink->id, 'token' => $plainTextToken]),
            ));

            AuditLog::record($user, 'auth.magic_link.requested', ip: $request->ip());
        }

        return back()->with('status', "If that's an Offing account, we've sent a magic link — check your email.");
    }

    /**
     * Consume a magic link and log the owning user in.
     */
    public function consume(Request $request, MagicLink $magicLink, string $token): RedirectResponse
    {
        if (! $magicLink->isUsable() || ! hash_equals($magicLink->token_hash, hash('sha256', $token))) {
            return redirect()->route('login')->withErrors([
                'email' => 'That link has expired or already been used — request a new one.',
            ]);
        }

        $magicLink->forceFill(['used_at' => now()])->save();

        $user = $magicLink->user;

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        AuditLog::record($user, 'auth.login', ip: $request->ip());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
