<?php

namespace App\Auth;

use App\Models\SignIn;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Remember-me tokens per sign-in (SignIn::$remember_hash) instead of
 * Laravel's one shared `users.remember_token`, so each browser can be
 * signed out on its own and a token is never stored in plain text.
 *
 * Cookies issued before sign-ins were kept still match the shared column
 * until TrackSignIns swaps them for their own, or "Sign out everywhere"
 * changes it. A token that ever belonged to a sign-in never falls back.
 */
class SignInUserProvider extends EloquentUserProvider
{
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $signIn = SignIn::where('remember_hash', hash('sha256', $token))->first();

        if ($signIn !== null) {
            return (string) $signIn->user_id === (string) $identifier && $signIn->isActive()
                ? $this->retrieveById($identifier)
                : null;
        }

        return parent::retrieveByToken($identifier, $token);
    }

    /**
     * The guard cycles the token on logout; with per-sign-in tokens that
     * must not sign every other browser out too, so nothing is saved.
     */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        $user->setRememberToken($token);
    }
}
