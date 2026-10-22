<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/barometer.php';
require __DIR__.'/crew.php';
require __DIR__.'/people.php';
require __DIR__.'/reminders.php';
require __DIR__.'/settings.php';
require __DIR__.'/shipshape.php';
require __DIR__.'/auth.php';
