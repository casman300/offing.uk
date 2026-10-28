<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Pennant Store
    |--------------------------------------------------------------------------
    |
    | Offing's `database` store uses App\Features\OverridesOnlyDriver
    | (registered in AppServiceProvider): the `features` table holds explicit
    | per-user overrides only, never resolved defaults.
    |
    */

    'default' => env('PENNANT_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'overrides-only',
            'connection' => null,
            'table' => 'features',
        ],

    ],
];
