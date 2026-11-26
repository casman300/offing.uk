<?php

use App\Features\Calendar;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Calendar\CalendarViewController;
use App\Http\Controllers\Calendar\EventController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// The calendar (spec §23 Phase 1b).
Route::middleware(['auth', EnsureFeaturesAreActive::using(Calendar::class)])->prefix('calendar')->name('calendar.')->group(function () {
    Route::get('/', [CalendarViewController::class, 'index'])->name('index');
    Route::get('occurrences', [CalendarViewController::class, 'occurrences'])->name('occurrences');

    Route::get('calendars', [CalendarController::class, 'index'])->name('calendars.index');
    Route::post('calendars', [CalendarController::class, 'store'])->name('calendars.store');
    Route::patch('calendars/{calendar}', [CalendarController::class, 'update'])->whereNumber('calendar')->name('calendars.update');
    Route::delete('calendars/{calendar}', [CalendarController::class, 'destroy'])->whereNumber('calendar')->name('calendars.destroy');

    Route::get('events/create', [EventController::class, 'create'])->name('events.create');
    Route::post('events', [EventController::class, 'store'])->name('events.store');
    Route::get('events/{event}', [EventController::class, 'show'])->whereNumber('event')->name('events.show');
    Route::get('events/{event}/edit', [EventController::class, 'edit'])->whereNumber('event')->name('events.edit');
    Route::put('events/{event}', [EventController::class, 'update'])->whereNumber('event')->name('events.update');
    Route::get('events/{event}/one/edit', [EventController::class, 'editOne'])->whereNumber('event')->name('events.one.edit');
    Route::post('events/{event}/one', [EventController::class, 'updateOne'])->whereNumber('event')->name('events.one.update');
    Route::delete('events/{event}', [EventController::class, 'destroy'])->whereNumber('event')->name('events.destroy');
    Route::post('events/{event}/restore', [EventController::class, 'restore'])->whereNumber('event')->name('events.restore');
    Route::post('events/{event}/move', [EventController::class, 'move'])->whereNumber('event')->name('events.move');
    Route::post('events/{event}/skip', [EventController::class, 'skip'])->whereNumber('event')->name('events.skip');
});
