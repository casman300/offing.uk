<?php

use App\Features\BirthdayEngine;
use App\Features\Reminders;
use App\Http\Controllers\Reminders\DeviceController;
use App\Http\Controllers\Reminders\ReminderSettingsController;
use App\Http\Controllers\Reminders\UnsubscribeController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// Settings → Reminders (#18). Needs People too: nothing to remind about otherwise.
Route::middleware(['auth', EnsureFeaturesAreActive::using(BirthdayEngine::class, Reminders::class)])->group(function () {
    Route::get('settings/reminders', [ReminderSettingsController::class, 'edit'])->name('reminders.edit');
    Route::patch('settings/reminders', [ReminderSettingsController::class, 'update'])->name('reminders.update');
    Route::post('settings/reminders/test', [ReminderSettingsController::class, 'test'])->middleware('throttle:3,1')->name('reminders.test');

    Route::post('settings/reminders/devices', [DeviceController::class, 'store'])->middleware('throttle:10,1')->name('reminders.devices.store');
    Route::delete('settings/reminders/devices/{device}', [DeviceController::class, 'destroy'])->whereNumber('device')->name('reminders.devices.destroy');
    Route::post('settings/reminders/devices/{device}/test', [DeviceController::class, 'test'])->whereNumber('device')->middleware('throttle:5,1')->name('reminders.devices.test');
});

// Unsubscribe links from reminder emails: signed, no sign-in, no flag check
// (an off switch must always work). POST /off is CSRF-exempt for RFC 8058.
Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('reminders/off/{user}', [UnsubscribeController::class, 'show'])->name('reminders.unsubscribe');
    Route::post('reminders/off/{user}', [UnsubscribeController::class, 'off'])->name('reminders.unsubscribe.confirm');
    Route::post('reminders/on/{user}', [UnsubscribeController::class, 'on'])->name('reminders.resubscribe');
});
