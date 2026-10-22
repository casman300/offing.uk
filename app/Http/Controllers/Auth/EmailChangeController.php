<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MagicLink;
use App\Support\Users\EmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The links in email-change emails (H1). Each opens a page with a button;
 * only the button's POST acts, so a mail scanner fetching the link changes
 * nothing. Neither link signs anyone in, so they work from any device.
 */
class EmailChangeController extends Controller
{
    public function showConfirm(Request $request, MagicLink $magicLink, string $token): Response|RedirectResponse
    {
        return $this->page($request, $magicLink, $token, MagicLink::EMAIL_CONFIRM);
    }

    public function confirm(Request $request, MagicLink $magicLink, string $token): RedirectResponse
    {
        if (! $magicLink->matches(MagicLink::EMAIL_CONFIRM, $token) || ! $magicLink->claim()) {
            return $this->rejected($request, $magicLink);
        }

        if (! EmailChange::confirm($magicLink, $request->ip())) {
            return $this->done($request, $magicLink, 'error', 'That address now belongs to another Offing account, so your email hasn’t changed.');
        }

        return $this->done($request, $magicLink, 'status', "Your email is now {$magicLink->email}. Use it to sign in from now on.");
    }

    public function showRevert(Request $request, MagicLink $magicLink, string $token): Response|RedirectResponse
    {
        return $this->page($request, $magicLink, $token, MagicLink::EMAIL_REVERT);
    }

    public function revert(Request $request, MagicLink $magicLink, string $token): RedirectResponse
    {
        if (! $magicLink->matches(MagicLink::EMAIL_REVERT, $token) || ! $magicLink->claim()) {
            return $this->rejected($request, $magicLink);
        }

        if (! EmailChange::revert($magicLink, $request->ip())) {
            return redirect()->route('login')->withErrors([
                'email' => "{$magicLink->email} now belongs to another Offing account, so we couldn’t put it back. Ask the person who runs your Offing to help.",
            ]);
        }

        // Every other session went with the revert; this one too, if it was theirs.
        if (Auth::id() === $magicLink->user_id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $destination = Auth::check() ? route('dashboard') : route('login');

        return redirect($destination)->with('status', "Done — your email is back to {$magicLink->email} and you’ve been signed out everywhere. Sign in again with a magic link.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        EmailChange::cancel($request->user(), $request->ip());

        return to_route('profile.edit')->with('status', 'email-change-cancelled');
    }

    private function page(Request $request, MagicLink $magicLink, string $token, string $purpose): Response|RedirectResponse
    {
        if (! $magicLink->matches($purpose, $token) || ! $magicLink->isUsable()) {
            return $this->rejected($request, $magicLink);
        }

        return Inertia::render('auth/email-change', [
            'mode' => $purpose === MagicLink::EMAIL_CONFIRM ? 'confirm' : 'revert',
            'email' => $magicLink->email,
            // The signed URL itself: the POST is checked the same way.
            'action' => $request->fullUrl(),
        ]);
    }

    private function rejected(Request $request, MagicLink $magicLink): RedirectResponse
    {
        AuditLog::record($magicLink->user, 'auth.email_change.link_rejected', $magicLink, ['purpose' => $magicLink->purpose], $request->ip());

        return redirect(Auth::check() ? route('profile.edit') : route('login'))->withErrors([
            'email' => 'That link has expired or already been used.',
        ]);
    }

    /**
     * Back to Settings if it's their own session, else to sign in. Whoever
     * else may be signed in on this browser is left alone.
     */
    private function done(Request $request, MagicLink $magicLink, string $key, string $message): RedirectResponse
    {
        $destination = match (true) {
            Auth::id() === $magicLink->user_id => route('profile.edit'),
            Auth::check() => route('dashboard'),
            default => route('login'),
        };

        return $key === 'error'
            ? redirect($destination)->withErrors(['email' => $message])
            : redirect($destination)->with('status', $message);
    }
}
