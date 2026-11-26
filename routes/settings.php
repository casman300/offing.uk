<?php

use App\Features\Calendar;
use App\Features\ConnectClaude;
use App\Http\Controllers\Settings\AiAssistantsController;
use App\Http\Controllers\Settings\FeedSubscriptionController;
use App\Http\Controllers\Settings\FeedTokenController;
use App\Http\Controllers\Settings\PasskeyController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

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

// AI assistants — Connect Claude (spec §12), for any MCP client.
Route::middleware(['auth', EnsureFeaturesAreActive::using(ConnectClaude::class)])->group(function () {
    Route::get('settings/ai-assistants', [AiAssistantsController::class, 'index'])->name('ai-assistants.index');
    Route::patch('settings/ai-assistants/{grant}', [AiAssistantsController::class, 'update'])->whereNumber('grant')->name('ai-assistants.update');
    Route::delete('settings/ai-assistants/{grant}', [AiAssistantsController::class, 'destroy'])->whereNumber('grant')->name('ai-assistants.destroy');
});

// Subscribed external calendars (spec §13), part of the calendar.
Route::middleware(['auth', EnsureFeaturesAreActive::using(Calendar::class)])->group(function () {
    Route::get('settings/subscriptions', [FeedSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('settings/subscriptions', [FeedSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('subscriptions.store');
    Route::patch('settings/subscriptions/{subscription}', [FeedSubscriptionController::class, 'update'])->whereNumber('subscription')->name('subscriptions.update');
    Route::post('settings/subscriptions/{subscription}/refresh', [FeedSubscriptionController::class, 'refresh'])->whereNumber('subscription')->middleware('throttle:10,1')->name('subscriptions.refresh');
    Route::delete('settings/subscriptions/{subscription}', [FeedSubscriptionController::class, 'destroy'])->whereNumber('subscription')->name('subscriptions.destroy');
});
