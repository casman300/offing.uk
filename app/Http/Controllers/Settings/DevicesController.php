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
 * you're signed in on, each of which can be signed out, or all the others
 * at once. Every sign-out is audited.
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
                        'ip_address' => $signIn->ip_address,
                        'method' => $signIn->method,
                        'current' => SignIn::isCurrent($request, $signIn),
                        'signed_in_at' => $signIn->signed_in_at?->toIso8601String(),
                        'last_active_at' => $signIn->last_active_at->toIso8601String(),
                    ])
                    ->sortByDesc('current')
                    ->values()
                : null,
        ]);
    }

    /**
     * Sign one browser out. It's turned away on its next request, and its
     * remember cookie stops working. This browser's own row signs out here
     * and now, like the menu's Log out.
     */
    public function destroy(Request $request, int $signIn): RedirectResponse
    {
        $user = $request->user();
        $model = SignIn::where('user_id', $user->id)->active()->findOrFail($signIn);

        AuditLog::record($user, 'auth.signed_out_device', $model, ['name' => SignIn::describe($model->user_agent)], $request->ip());

        if (SignIn::isCurrent($request, $model)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        $model->signOut();

        return to_route('devices.index')->with('status', 'signed-out-device');
    }

    /**
     * Every other browser at once. This one stays signed in.
     */
    public function signOutOthers(Request $request): RedirectResponse
    {
        $user = $request->user();

        $count = SignIn::signOutEverywhere($user, except: SignIn::forRequest($request, $user), exceptSessionId: $request->session()->getId());

        AuditLog::record($user, 'auth.signed_out_others', meta: ['devices' => $count], ip: $request->ip());

        return to_route('devices.index')->with('status', 'signed-out-others');
    }
}
