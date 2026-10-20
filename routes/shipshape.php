<?php

use App\Features\Shipshape;
use App\Http\Controllers\Shipshape\BoardController;
use App\Http\Controllers\Shipshape\ChoreController;
use App\Http\Controllers\Shipshape\ChoreListController;
use App\Http\Controllers\Shipshape\EntryController;
use App\Http\Controllers\Shipshape\StarterController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// Shipshape — household chores (spec §25).
Route::middleware(['auth', EnsureFeaturesAreActive::using(Shipshape::class)])->prefix('shipshape')->name('shipshape.')->group(function () {
    Route::get('/', BoardController::class)->name('board');

    Route::get('lists', [ChoreListController::class, 'index'])->name('lists.index');
    Route::post('lists', [ChoreListController::class, 'store'])->name('lists.store');
    Route::get('lists/{list}', [ChoreListController::class, 'show'])->whereNumber('list')->name('lists.show');
    Route::patch('lists/{list}', [ChoreListController::class, 'update'])->whereNumber('list')->name('lists.update');
    Route::delete('lists/{list}', [ChoreListController::class, 'destroy'])->whereNumber('list')->name('lists.destroy');

    Route::get('lists/{list}/starters', [StarterController::class, 'show'])->whereNumber('list')->name('starters.show');
    Route::post('lists/{list}/starters', [StarterController::class, 'store'])->whereNumber('list')->name('starters.store');

    Route::get('lists/{list}/chores/create', [ChoreController::class, 'create'])->whereNumber('list')->name('chores.create');
    Route::post('lists/{list}/chores', [ChoreController::class, 'store'])->whereNumber('list')->name('chores.store');
    Route::get('chores/{chore}/edit', [ChoreController::class, 'edit'])->whereNumber('chore')->name('chores.edit');
    Route::put('chores/{chore}', [ChoreController::class, 'update'])->whereNumber('chore')->name('chores.update');
    Route::delete('chores/{chore}', [ChoreController::class, 'destroy'])->whereNumber('chore')->name('chores.destroy');
    Route::post('chores/{chore}/restore', [ChoreController::class, 'restore'])->whereNumber('chore')->name('chores.restore');

    Route::post('chores/{chore}/log', [EntryController::class, 'store'])->whereNumber('chore')->name('chores.log');
    Route::post('entries/{entry}/undo', [EntryController::class, 'undo'])->whereNumber('entry')->name('entries.undo');
});
