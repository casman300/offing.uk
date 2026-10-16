<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Use Face ID next time?", shown after a magic-link sign-in (spec §15,
 * #9). "Not now" is remembered on this device for 30 days. Registering
 * goes through Settings\PasskeyController with `from_setup`, which then
 * continues to wherever the user was heading.
 */
class PasskeySetupController extends Controller
{
    public const DISMISS_COOKIE = 'passkey_prompt_dismissed';

    private const DISMISS_MINUTES = 60 * 24 * 30;

    public function show(): Response
    {
        return Inertia::render('auth/passkey-setup');
    }

    public function dismiss(Request $request): RedirectResponse
    {
        Cookie::queue(self::DISMISS_COOKIE, '1', self::DISMISS_MINUTES);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Whether to show the prompt after a magic-link sign-in on this device.
     */
    public static function shouldPrompt(Request $request): bool
    {
        return ! $request->cookie(self::DISMISS_COOKIE);
    }
}
