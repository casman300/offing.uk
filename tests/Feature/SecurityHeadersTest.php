<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\FeedToken;
use App\Models\User;

/*
| Security audit M2: hardening headers on every response.
*/

it('sends the hardening headers on pages', function () {
    $this->get('/login')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeader('Content-Security-Policy-Report-Only', SecurityHeaders::contentSecurityPolicy());

    $this->actingAs(User::factory()->create())->get('/settings/profile')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('keeps the policy tight, and lets the consent form reach OAuth clients', function () {
    $csp = SecurityHeaders::contentSecurityPolicy();

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self';")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("form-action 'self' https://claude.ai https://claude.com https://chatgpt.com http://localhost:* http://127.0.0.1:*")
        ->not->toContain('unsafe-eval')
        ->not->toContain('localhost:5173');
});

it('allows the Vite dev server only in local', function () {
    $this->app['env'] = 'local';

    expect(SecurityHeaders::contentSecurityPolicy())->toContain("script-src 'self' http://localhost:5173 ws://localhost:5173;");
});

it('sends HSTS when the app is served over https, never in local or over http', function () {
    config(['app.url' => 'https://offing.test']);
    $this->get('/login')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);

    config(['app.url' => 'http://offing.test']);
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    config(['app.url' => 'https://offing.test']);
    $this->app['env'] = 'local';
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('covers the session-less routes too, without overriding their own referrer policy', function () {
    [, $plaintext] = FeedToken::mint(User::factory()->create(), 'Phone');

    $this->get("/feed/{$plaintext}.ics")
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    // Connect Claude's public OAuth discovery, also outside the web group.
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
