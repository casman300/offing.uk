<?php

use App\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;

// Outbound ICS feeds (#15). Loaded without the `web` group (bootstrap/app.php):
// calendar apps poll these, and they need no session, cookies, CSRF or Inertia.
Route::get('feed/{token}.ics', [FeedController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('feed.show');
