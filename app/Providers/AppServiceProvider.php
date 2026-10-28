<?php

namespace App\Providers;

use App\Features\OverridesOnlyDriver;
use App\Features\StageOverrides;
use App\Http\Controllers\ConnectClaude\ConsentController;
use App\Models\SignIn;
use App\Models\User;
use App\Support\Push\PushSender;
use App\Support\Push\WebPushSender;
use Carbon\CarbonInterval;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Connect Claude only uses the authorization-code grant (spec §12);
        // set before Passport boots and registers the device-flow routes.
        Passport::$deviceCodeGrantEnabled = false;

        // Stages set on /admin/features, read once per request.
        $this->app->scoped(StageOverrides::class);

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

        // However a browser signs out, it leaves Settings → Sign-in & devices.
        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                SignIn::markSignedOut(request(), $event->user);
            }
        });

        // The `features` table holds per-user overrides only (see the driver).
        Feature::extend('overrides-only', fn ($app) => new OverridesOnlyDriver($app['db'], $app['events'], $app['config'], 'database', []));

        // A dark-launched feature should look like it doesn't exist, not
        // like a broken request (Pennant's default is a 400).
        EnsureFeaturesAreActive::whenInactive(fn () => abort(404));

        // Connect Claude (spec §12): our own consent screen, and short-lived
        // access tokens — Claude refreshes them, and a revoke or a lost
        // device then stops mattering within the hour.
        Passport::authorizationView(function (array $parameters) {
            /** @var array{client: Client, user: User, authToken: string, request: Request} $parameters */
            return app(ConsentController::class)->show($parameters)->toResponse($parameters['request']);
        });
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(90));
    }
}
