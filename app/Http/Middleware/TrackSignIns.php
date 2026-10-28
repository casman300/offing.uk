<?php

namespace App\Http\Middleware;

use App\Models\SignIn;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps each browser's SignIn row current, and ends the session of a
 * browser that was signed out from another one ("Sign out everywhere
 * else"), whatever the session driver or remember cookie still say.
 */
class TrackSignIns
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $signIn = SignIn::current($request, $user);

            if ($signIn->signed_out_at !== null) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }

            $signIn->touchActivity($request);
        }

        return $next($request);
    }
}
