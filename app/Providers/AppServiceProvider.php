<?php

namespace App\Providers;

use App\Support\Push\PushSender;
use App\Support\Push\WebPushSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PushSender::class, fn () => new WebPushSender(
            config('offing.vapid.public_key'),
            config('offing.vapid.private_key'),
            (string) config('offing.vapid.subject'),
        ));
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

        // A dark-launched feature should look like it doesn't exist, not
        // like a broken request (Pennant's default is a 400).
        EnsureFeaturesAreActive::whenInactive(fn () => abort(404));
    }
}
