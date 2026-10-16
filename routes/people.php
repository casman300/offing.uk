<?php

use App\Features\BirthdayEngine;
use App\Features\VcardImport;
use App\Http\Controllers\People\ContactImportController;
use App\Http\Controllers\People\PersonController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::middleware(['auth', EnsureFeaturesAreActive::using(BirthdayEngine::class)])->group(function () {
    Route::get('people', [PersonController::class, 'index'])->name('people.index');
    Route::get('people/create', [PersonController::class, 'create'])->name('people.create');
    Route::post('people', [PersonController::class, 'store'])->name('people.store');
    Route::get('people/{person}', [PersonController::class, 'show'])->whereNumber('person')->name('people.show');
    Route::patch('people/{person}', [PersonController::class, 'update'])->whereNumber('person')->name('people.update');
    Route::delete('people/{person}', [PersonController::class, 'destroy'])->whereNumber('person')->name('people.destroy');
    Route::post('people/{person}/restore', [PersonController::class, 'restore'])->whereNumber('person')->name('people.restore');
});

// vCard import (#14) — needs both flags: importing into People you can't see makes no sense.
Route::middleware(['auth', EnsureFeaturesAreActive::using(BirthdayEngine::class, VcardImport::class)])->group(function () {
    Route::get('people/import', [ContactImportController::class, 'create'])->name('people.import.create');
    Route::post('people/import', [ContactImportController::class, 'store'])->name('people.import.store');
    Route::get('people/import/{import}', [ContactImportController::class, 'show'])->whereNumber('import')->name('people.import.show');
    Route::post('people/import/{import}/commit', [ContactImportController::class, 'commit'])->whereNumber('import')->name('people.import.commit');
    Route::post('people/import/{import}/undo', [ContactImportController::class, 'undo'])->whereNumber('import')->name('people.import.undo');
    Route::delete('people/import/{import}', [ContactImportController::class, 'destroy'])->whereNumber('import')->name('people.import.destroy');
});
