<?php

use App\Features\Calendar;
use App\Http\Controllers\Calendar\EventController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::middleware(['auth', EnsureFeaturesAreActive::using(Calendar::class)])->group(function () {
    Route::get('calendar', [EventController::class, 'index'])->name('calendar.index');
    Route::get('calendar/events/create', [EventController::class, 'create'])->name('calendar.events.create');
    Route::post('calendar/events', [EventController::class, 'store'])->name('calendar.events.store');
    Route::get('calendar/events/{event}/edit', [EventController::class, 'edit'])->whereNumber('event')->name('calendar.events.edit');
    Route::patch('calendar/events/{event}', [EventController::class, 'update'])->whereNumber('event')->name('calendar.events.update');
    Route::delete('calendar/events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('calendar.events.destroy');
    Route::post('calendar/events/{event}/restore', [EventController::class, 'restore'])->whereNumber('event')->name('calendar.events.restore');
});
