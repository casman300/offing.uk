<?php

use App\Features\Barometer;
use App\Http\Controllers\Barometer\CheckInController;
use App\Http\Controllers\Barometer\ExportController;
use App\Http\Controllers\Barometer\ReadingController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// Barometer (#35): owner-only, so every route is a 404 while the flag is off.
Route::middleware(['auth', EnsureFeaturesAreActive::using(Barometer::class)])->group(function () {
    Route::get('barometer', [ReadingController::class, 'index'])->name('barometer.index');
    Route::get('barometer/create', [ReadingController::class, 'create'])->name('barometer.create');
    Route::post('barometer', [ReadingController::class, 'store'])->name('barometer.store');
    Route::get('barometer/export', [ExportController::class, 'show'])->name('barometer.export');
    Route::get('barometer/export/download', [ExportController::class, 'download'])->middleware('throttle:10,1')->name('barometer.export.download');
    Route::get('barometer/{reading}/edit', [ReadingController::class, 'edit'])->whereNumber('reading')->name('barometer.edit');
    Route::patch('barometer/{reading}', [ReadingController::class, 'update'])->whereNumber('reading')->name('barometer.update');
    Route::delete('barometer/{reading}', [ReadingController::class, 'destroy'])->whereNumber('reading')->name('barometer.destroy');
    Route::patch('barometer/check-ins', [CheckInController::class, 'update'])->name('barometer.check-ins.update');
});

// Turning check-ins off from the email (#36): signed, no sign-in, no flag
// check (an off switch must always work). POST /off is CSRF-exempt for RFC 8058.
Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('barometer/check-ins/off/{user}', [CheckInController::class, 'show'])->name('barometer.check-ins.show');
    Route::post('barometer/check-ins/off/{user}', [CheckInController::class, 'off'])->name('barometer.check-ins.off');
    Route::post('barometer/check-ins/on/{user}', [CheckInController::class, 'on'])->name('barometer.check-ins.on');
});
