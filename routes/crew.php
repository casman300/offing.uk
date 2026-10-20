<?php

use App\Features\Crews;
use App\Http\Controllers\CrewController;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

// Crews (spec §7) — the shared spaces Shipshape and later calendars hang off.
Route::middleware(['auth', EnsureFeaturesAreActive::using(Crews::class)])->group(function () {
    Route::get('crew', [CrewController::class, 'index'])->name('crew.index');
    Route::post('crew', [CrewController::class, 'store'])->name('crew.store');

    Route::post('crew/invites/{invite}/accept', [CrewController::class, 'acceptInvite'])->whereNumber('invite')->name('crew.invites.accept');
    Route::post('crew/invites/{invite}/decline', [CrewController::class, 'declineInvite'])->whereNumber('invite')->name('crew.invites.decline');

    Route::get('crew/{crew}', [CrewController::class, 'show'])->whereNumber('crew')->name('crew.show');
    Route::patch('crew/{crew}', [CrewController::class, 'update'])->whereNumber('crew')->name('crew.update');
    Route::delete('crew/{crew}', [CrewController::class, 'destroy'])->whereNumber('crew')->name('crew.destroy');
    Route::post('crew/{crew}/leave', [CrewController::class, 'leave'])->whereNumber('crew')->name('crew.leave');

    Route::post('crew/{crew}/invites', [CrewController::class, 'invite'])->whereNumber('crew')->name('crew.invites.store');
    Route::delete('crew/{crew}/invites/{invite}', [CrewController::class, 'revokeInvite'])->whereNumber(['crew', 'invite'])->name('crew.invites.revoke');

    Route::post('crew/{crew}/passengers', [CrewController::class, 'addPassenger'])->whereNumber('crew')->name('crew.passengers.store');
    Route::post('crew/{crew}/members/{member}/readd', [CrewController::class, 'readd'])->whereNumber(['crew', 'member'])->name('crew.members.readd');
    Route::patch('crew/{crew}/members/{member}', [CrewController::class, 'changeRole'])->whereNumber(['crew', 'member'])->name('crew.members.role');
    Route::delete('crew/{crew}/members/{member}', [CrewController::class, 'removeMember'])->whereNumber(['crew', 'member'])->name('crew.members.remove');
});
