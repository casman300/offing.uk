<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

// Laravel's own .env loading (LoadEnvironmentVariables) doesn't run until the
// HTTP/console kernel bootstraps — too late for usePublicPath() below, and it
// skips entirely once config is cached (which every deploy does). Load it
// ourselves, early and unconditionally, so PUBLIC_PATH is readable here.
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

// 20i (and most shared hosting) doesn't let every plan change its document
// root, so in production/staging the webroot is public_html directly and
// the app lives in a sibling "laravel" directory next to it (outside the
// webroot) — see .github/workflows/deploy-*.yml. Local dev is untouched:
// PUBLIC_PATH is unset, so public_path() keeps resolving to public/.
if ($publicPath = env('PUBLIC_PATH')) {
    $app->usePublicPath($publicPath);
}

return $app;
