<?php

use App\Support\SentryScrubber;

/*
 * Error tracking only (spec §22) — no performance tracing, profiling, logs
 * or metrics, and no personal data (spec §8: no tracking, no sharing user
 * data). The privacy settings are fixed here rather than read from .env, so
 * a stray env line can't switch them on. Login tokens are scrubbed from
 * URLs by App\Support\SentryScrubber.
 *
 * With no DSN set (local dev, CI) the SDK does nothing.
 *
 * Full option list: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),

    // Defaults to APP_ENV (staging/production) when unset.
    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => 1.0,
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,
    'enable_logs' => false,
    'enable_metrics' => false,

    // No IPs, cookies, user ids/emails or request bodies (the login form
    // posts an email address).
    'send_default_pii' => false,
    'max_request_body_size' => 'never',

    'before_send' => [SentryScrubber::class, 'beforeSend'],
    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        // Bindings are the values in a query — emails, event titles, names.
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

];
