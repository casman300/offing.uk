<?php

namespace App\Http\Middleware;

use App\Models\SignIn;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every signed-in request belongs to a SignIn (see the model). One that
 * was signed out from another browser, or has run out, ends here, however
 * long its session or remember cookie would otherwise have lasted.
 */
class TrackSignIns
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $signIn = SignIn::forRequest($request, $user);

        if (! $signIn->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('login'));
        }

        $signIn->touchActivity($request);

        return $next($request);
    }
}
