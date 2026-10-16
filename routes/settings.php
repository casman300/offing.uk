<?php

use App\Http\Controllers\Settings\FeedTokenController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::get('settings/passkeys', [PasskeyController::class, 'index'])->name('passkeys.index');
    Route::post('settings/passkeys/options', [PasskeyController::class, 'options'])->middleware('throttle:20,1')->name('passkeys.options');
    Route::post('settings/passkeys', [PasskeyController::class, 'store'])->middleware('throttle:20,1')->name('passkeys.store');
    Route::patch('settings/passkeys/{passkey}', [PasskeyController::class, 'update'])->whereNumber('passkey')->name('passkeys.update');
    Route::delete('settings/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->whereNumber('passkey')->name('passkeys.destroy');

    Route::get('settings/feeds', [FeedTokenController::class, 'index'])->name('feeds.index');
    Route::post('settings/feeds', [FeedTokenController::class, 'store'])->name('feeds.store');
    Route::delete('settings/feeds/{token}', [FeedTokenController::class, 'destroy'])->whereNumber('token')->name('feeds.destroy');
});
