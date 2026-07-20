<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keyed by target email (not IP) so a magic-link request can't be used
        // to mail-bomb one address from multiple IPs — the route's throttle:6,1
        // middleware only limits per-IP.
        RateLimiter::for('magic-link-email', function (Request $request) {
            return Limit::perMinute(3)->by(Str::lower((string) $request->input('email')));
        });
    }
}
