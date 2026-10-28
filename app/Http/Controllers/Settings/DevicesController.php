<?php

namespace App\Http\Controllers\Settings;

use App\Features\SignInDevices;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\SignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * Settings → Sign-in & devices (spec §15): your passkeys, and every browser
 * you're signed in on, with one "Sign out everywhere else".
 */
class DevicesController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/devices', [
            'passkeys' => $user->passkeys()->latest('id')->get()
                ->map(fn (Passkey $passkey) => [
                    'id' => $passkey->id,
                    'name' => $passkey->name,
                    'created_at' => $passkey->created_at?->toIso8601String(),
                    'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                ])
                ->values(),
            'signIns' => Feature::for($user)->active(SignInDevices::class)
                ? SignIn::where('user_id', $user->id)->active()->latest('last_active_at')->get()
                    ->map(fn (SignIn $signIn) => [
                        'id' => $signIn->id,
                        'name' => SignIn::describe($signIn->user_agent),
                        'method' => $signIn->method,
                        'current' => SignIn::isCurrentDevice($request, $signIn),
                        'signed_in_at' => $signIn->signed_in_at?->toIso8601String(),
                        'last_active_at' => $signIn->last_active_at->toIso8601String(),
                    ])
                    ->sortByDesc('current')
                    ->values()
                : null,
        ]);
    }

    /**
     * One button, every other browser. This one stays signed in, with a
     * fresh remember cookie if it had one (the old token no longer works).
     */
    public function signOutOthers(Request $request): RedirectResponse
    {
        $user = $request->user();

        $count = SignIn::signOutEverywhere($user, except: SignIn::current($request, $user), exceptSessionId: $request->session()->getId());
        Auth::guard('web')->login($user, remember: SignIn::hasRememberCookie($request));

        AuditLog::record($user, 'auth.signed_out_others', meta: ['devices' => $count], ip: $request->ip());

        return to_route('devices.index')->with('status', 'signed-out-others');
    }
}
