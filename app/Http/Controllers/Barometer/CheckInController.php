<?php

namespace App\Http\Controllers\Barometer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Barometer check-ins on/off (#36): the switch on the Barometer page, and
 * the signed, sign-in-free link in check-in emails.
 *
 * As with reminders, the link's GET only shows a page with a button — link
 * scanners and mail previews fetch GET URLs and must not switch anything
 * off. The POST (also what RFC 8058 one-click sends) does the work.
 */
class CheckInController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(['on' => ['required', 'boolean']]);

        self::set($request->user(), (bool) $validated['on'], 'settings', $request->ip());

        return back();
    }

    public function show(User $user): Response
    {
        return Inertia::render('barometer/check-ins', [
            'on' => $user->barometer_check_ins,
            'offUrl' => URL::signedRoute('barometer.check-ins.off', ['user' => $user->id]),
            'onUrl' => URL::signedRoute('barometer.check-ins.on', ['user' => $user->id]),
        ]);
    }

    public function off(Request $request, User $user): RedirectResponse|HttpResponse
    {
        self::set($user, false, 'unsubscribe_link', $request->ip());

        // A mail app's one-click POST wants a plain 2xx, not a page.
        if (! $request->header('X-Inertia')) {
            return response('Check-ins turned off.', 200);
        }

        return back();
    }

    public function on(Request $request, User $user): RedirectResponse
    {
        self::set($user, true, 'unsubscribe_link', $request->ip());

        return back();
    }

    private static function set(User $user, bool $on, string $via, ?string $ip): void
    {
        if ($user->barometer_check_ins === $on) {
            return;
        }

        $user->forceFill(['barometer_check_ins' => $on])->save();
        AuditLog::record($user, $on ? 'barometer.check_ins.enabled' : 'barometer.check_ins.disabled', $user, ['via' => $via], $ip);
    }
}
