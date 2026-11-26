<?php

return [

    /*
    | Who can open /admin. A comma-separated list in each host's .env — set by
    | hand on the server, never editable from inside the app. Matched
    | case-insensitively against the signed-in user's email.
    */
    'admin_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ADMIN_EMAILS', '')),
    ))),

    /*
    | healthchecks.io (or compatible) ping URL. The scheduler hits it every
    | minute; set up the check to alert when pings stop, i.e. cron has died.
    */
    'healthcheck_url' => env('HEALTHCHECK_URL'),

    /*
    | Strict-Transport-Security normally covers only the host in APP_URL. Set
    | this to also cover every subdomain under it, for a year, in any browser
    | that visits; only do so if every one of them serves https.
    */
    'hsts_include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),

    /*
    | Web Push (VAPID) keys — generate once per environment with
    | `artisan push:keys` and paste into .env. Push stays hidden until both
    | keys are set. Changing them invalidates every device's subscription.
    */
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:hello@offing.uk'),
    ],

    /*
    | Calendar subscriptions (spec §13). Offing fetches the URLs people paste,
    | so only public internet addresses are allowed. `allow_private_hosts`
    | lets it fetch from loopback/private addresses and any port — only for
    | testing against a local fixture server; never set it on a real host.
    */
    'feeds' => [
        'allow_private_hosts' => (bool) env('FEEDS_ALLOW_PRIVATE_HOSTS', false),
        'max_bytes' => 5 * 1024 * 1024,
    ],

];
