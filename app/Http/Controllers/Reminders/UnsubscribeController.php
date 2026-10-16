<?php

namespace App\Http\Controllers\Reminders;

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
 * Signed, sign-in-free unsubscribe for reminder emails.
 *
 * GET only shows a page with a button: link scanners and mail previews
 * fetch GET URLs, and must not be able to switch anyone's reminders off.
 * The POST (also what RFC 8058 one-click sends) does the work.
 */
class UnsubscribeController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        return Inertia::render('reminders/unsubscribe', [
            'on' => $user->remindersOn(),
            'offUrl' => URL::signedRoute('reminders.unsubscribe', ['user' => $user->id]),
            'onUrl' => URL::signedRoute('reminders.resubscribe', ['user' => $user->id]),
        ]);
    }

    public function off(Request $request, User $user): RedirectResponse|HttpResponse
    {
        if ($user->remindersOn()) {
            $user->forceFill(['reminders_enabled_at' => null])->save();
            AuditLog::record($user, 'reminders.disabled', $user, ['via' => 'unsubscribe_link'], $request->ip());
        }

        // A mail app's one-click POST wants a plain 2xx, not a page.
        if (! $request->header('X-Inertia')) {
            return response('Reminders turned off.', 200);
        }

        return back();
    }

    public function on(Request $request, User $user): RedirectResponse
    {
        if (! $user->remindersOn()) {
            $user->forceFill(['reminders_enabled_at' => now()])->save();
            AuditLog::record($user, 'reminders.enabled', $user, ['via' => 'unsubscribe_link'], $request->ip());
        }

        return back();
    }
}
