<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AuditLog;
use App\Support\Users\EmailChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    private const EMAIL_CHANGES_PER_HOUR = 5;

    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'status' => $request->session()->get('status'),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'pendingEmail' => EmailChange::pending($request->user()),
        ]);
    }

    /**
     * Update the user's profile settings. A new email isn't saved here: it
     * gets a confirm link, and changes only once that's clicked (H1).
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $newEmail = $validated['email'] !== $user->email ? $validated['email'] : null;
        $key = 'email-change:'.$user->id;

        if ($newEmail !== null && RateLimiter::tooManyAttempts($key, self::EMAIL_CHANGES_PER_HOUR)) {
            throw ValidationException::withMessages(['email' => 'Too many email changes — try again in an hour.']);
        }

        $user->fill(Arr::except($validated, 'email'))->save();

        if ($newEmail === null) {
            return to_route('profile.edit');
        }

        RateLimiter::hit($key, 3600);
        EmailChange::request($user, $newEmail, $request->ip());

        return to_route('profile.edit')->with('status', 'email-change-sent');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        AuditLog::record($user, 'auth.account_deleted', ip: $request->ip());

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
