<?php

use App\Features\ConnectClaude;
use App\Features\SignInDevices;
use App\Http\Controllers\Settings\AiAssistantsController;
use App\Http\Controllers\Settings\ConnectedAppsController;
use App\Http\Controllers\Settings\DevicesController;
use App\Http\Controllers\Settings\FeedTokenController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Settings pages that were folded into others keep their old addresses.
    Route::redirect('settings/appearance', 'settings/profile');
    Route::redirect('settings/passkeys', 'settings/devices');
    Route::redirect('settings/feeds', 'settings/connected-apps');

    Route::get('settings/devices', [DevicesController::class, 'index'])->name('devices.index');
    Route::post('settings/devices/sign-out-others', [DevicesController::class, 'signOutOthers'])
        ->middleware(EnsureFeaturesAreActive::using(SignInDevices::class))
        ->name('devices.sign-out-others');

    Route::post('settings/passkeys/options', [PasskeyController::class, 'options'])->middleware('throttle:20,1')->name('passkeys.options');
    Route::post('settings/passkeys', [PasskeyController::class, 'store'])->middleware('throttle:20,1')->name('passkeys.store');
    Route::patch('settings/passkeys/{passkey}', [PasskeyController::class, 'update'])->whereNumber('passkey')->name('passkeys.update');
    Route::delete('settings/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->whereNumber('passkey')->name('passkeys.destroy');

    Route::get('settings/connected-apps', [ConnectedAppsController::class, 'index'])->name('connected-apps.index');
    Route::post('settings/feeds', [FeedTokenController::class, 'store'])->name('feeds.store');
    Route::delete('settings/feeds/{token}', [FeedTokenController::class, 'destroy'])->whereNumber('token')->name('feeds.destroy');
});

// AI assistants — Connect Claude (spec §12), for any MCP client.
Route::middleware(['auth', EnsureFeaturesAreActive::using(ConnectClaude::class)])->group(function () {
    Route::redirect('settings/ai-assistants', 'settings/connected-apps');
    Route::patch('settings/ai-assistants/{grant}', [AiAssistantsController::class, 'update'])->whereNumber('grant')->name('ai-assistants.update');
    Route::delete('settings/ai-assistants/{grant}', [AiAssistantsController::class, 'destroy'])->whereNumber('grant')->name('ai-assistants.destroy');
});
