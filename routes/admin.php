<?php

use App\Http\Controllers\Admin\FeatureFlagController;
use Illuminate\Support\Facades\Route;

// Admin shell (#11). Everything here is ADMIN_EMAILS-only and 404s for
// everyone else, signed in or not.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/features')->name('home');

    Route::get('features', [FeatureFlagController::class, 'index'])->name('features.index');
    Route::post('features/{feature}/users/{user}', [FeatureFlagController::class, 'update'])->name('features.update');
    Route::post('features/{feature}/reset', [FeatureFlagController::class, 'reset'])->name('features.reset');
});
