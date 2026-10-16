<?php

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Http\Controllers\Reminders\ReminderSettingsController;
use App\Http\Controllers\Reminders\UnsubscribeController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// Settings → Reminders (#18). Needs People too: nothing to remind about otherwise.
Route::middleware(['auth', EnsureFeaturesAreActive::using(BirthdayEngine::class, Reminders::class)])->group(function () {
    Route::get('settings/reminders', [ReminderSettingsController::class, 'edit'])->name('reminders.edit');
    Route::patch('settings/reminders', [ReminderSettingsController::class, 'update'])->name('reminders.update');
    Route::post('settings/reminders/test', [ReminderSettingsController::class, 'test'])->middleware('throttle:3,1')->name('reminders.test');
});

// Unsubscribe links from reminder emails: signed, no sign-in, no flag check
// (an off switch must always work). POST /off is CSRF-exempt for RFC 8058.
Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('reminders/off/{user}', [UnsubscribeController::class, 'show'])->name('reminders.unsubscribe');
    Route::post('reminders/off/{user}', [UnsubscribeController::class, 'off'])->name('reminders.unsubscribe.confirm');
    Route::post('reminders/on/{user}', [UnsubscribeController::class, 'on'])->name('reminders.resubscribe');
});
