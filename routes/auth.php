<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [MagicLinkController::class, 'create'])
        ->name('login');

    Route::post('login', [MagicLinkController::class, 'store'])
        ->middleware(['throttle:6,1', 'throttle:magic-link-email'])
        ->name('login.store');
});

// Deliberately outside the 'guest' group: an already-authenticated session
// (e.g. a shared device) must still be able to consume a link minted for a
// different account — see MagicLinkController::consume(), which swaps the
// session rather than silently redirecting the wrong user to /dashboard.
Route::get('login/{magicLink}/{token}', [MagicLinkController::class, 'consume'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('login.consume');

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
