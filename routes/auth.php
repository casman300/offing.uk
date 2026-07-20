<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\MagicLinkController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [MagicLinkController::class, 'create'])
        ->name('login');

    Route::post('login', [MagicLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('login.store');

    Route::get('login/{magicLink}/{token}', [MagicLinkController::class, 'consume'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('login.consume');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
