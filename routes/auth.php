<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailChangeController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasskeySetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [MagicLinkController::class, 'create'])
        ->name('login');

    Route::post('login', [MagicLinkController::class, 'store'])
        ->middleware(['throttle:6,1', 'throttle:magic-link-email'])
        ->name('login.store');

    // Passkey sign-in (#9): options are fetched as JSON; the signed
    // assertion comes back as an ordinary Inertia form post.
    Route::post('login/passkey/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:30,1')
        ->name('passkeys.login.options');
    Route::post('login/passkey', [PasskeyLoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('passkeys.login');
});

// Deliberately outside the 'guest' group: an already-authenticated session
// (e.g. a shared device) must still be able to consume a link minted for a
// different account — see MagicLinkController::consume(), which swaps the
// session rather than silently redirecting the wrong user to /dashboard.
Route::get('login/{magicLink}/{token}', [MagicLinkController::class, 'consume'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('login.consume');

// Email change links (H1): signed, no sign-in needed — they're opened from
// whichever inbox they went to. GET shows a button; only the POST acts.
Route::middleware(['signed', 'throttle:6,1'])->group(function () {
    Route::get('email/confirm/{magicLink}/{token}', [EmailChangeController::class, 'showConfirm'])->name('email-change.confirm');
    Route::post('email/confirm/{magicLink}/{token}', [EmailChangeController::class, 'confirm'])->name('email-change.confirm.store');
    Route::get('email/revert/{magicLink}/{token}', [EmailChangeController::class, 'showRevert'])->name('email-change.revert');
    Route::post('email/revert/{magicLink}/{token}', [EmailChangeController::class, 'revert'])->name('email-change.revert.store');
});

Route::middleware('auth')->group(function () {
    Route::post('settings/profile/email/cancel', [EmailChangeController::class, 'cancel'])->name('email-change.cancel');

    Route::get('passkeys/setup', [PasskeySetupController::class, 'show'])->name('passkeys.setup');
    Route::post('passkeys/setup/dismiss', [PasskeySetupController::class, 'dismiss'])->name('passkeys.setup.dismiss');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
