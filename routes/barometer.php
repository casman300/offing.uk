<?php

use App\Features\Barometer;
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
});
