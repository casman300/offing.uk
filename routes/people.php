<?php

use App\Features\BirthdayEngine;
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
