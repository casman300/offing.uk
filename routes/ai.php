<?php

use App\Http\Controllers\ConnectClaude\ConsentController;
use App\Http\Middleware\EnsureAiGrant;
use App\Mcp\OffingServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/*
| Connect Claude (spec §12). laravel/mcp loads this file itself.
|
| OAuth discovery documents are public. Dynamic client registration is open
| by design (that's how Claude connects), so it's throttled here and
| limited to known redirect hosts in config/mcp.php.
*/

Mcp::oauthRoutes(registration: false);

Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('mcp.oauth.register');

Mcp::web('/mcp', OffingServer::class)
    ->middleware(['auth:api', EnsureAiGrant::class, 'throttle:120,1']);

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('connect-claude/approve', [ConsentController::class, 'approve'])->name('connect-claude.approve');
    Route::post('connect-claude/deny', [ConsentController::class, 'deny'])->name('connect-claude.deny');
});
