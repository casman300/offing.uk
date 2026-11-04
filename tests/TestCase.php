<?php

namespace Tests;

use App\Models\SignIn;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Acting as someone is a fresh sign-in, as it would be in a browser.
     * The test client keeps one session store across requests, so without
     * this a sign-in ended by an earlier logout would still be named in it
     * and TrackSignIns would (rightly) turn the request away. Name a sign-in
     * with withSession() after this to act as that one.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        $this->app['session.store']->forget(SignIn::SESSION_KEY);

        return parent::actingAs($user, $guard);
    }
}
