<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SignIn;
use App\Support\Passkeys\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Passkey sign-in (#9). A verified passkey signs its owner in on its own,
 * with the same long-lived remember cookie a remembered magic link gets.
 */
class PasskeyLoginController extends Controller
{
    public function options(WebAuthn $webAuthn): JsonResponse
    {
        return JsonResponse::fromJsonString($webAuthn->loginOptions());
    }

    public function store(Request $request, WebAuthn $webAuthn): RedirectResponse
    {
        $request->validate(['credential' => ['required', 'string', 'max:16384']]);

        $passkey = $webAuthn->verifyLogin($request->string('credential')->toString());

        if ($passkey === null) {
            AuditLog::record(null, 'auth.passkey.rejected', ip: $request->ip());

            return back()->withErrors([
                'passkey' => "That passkey didn't work. Try again, or get a magic link instead.",
            ]);
        }

        $user = $passkey->user;

        SignIn::start($request, $user, 'passkey', remember: true);

        AuditLog::record($user, 'auth.login', $passkey, ['method' => 'passkey'], $request->ip());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
