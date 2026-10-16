<?php

// Only the keys that differ from the package defaults. Our page directory is
// lowercase `pages/` (react-starter-kit), which the default `js/Pages` path
// misses on case-sensitive filesystems such as CI's Linux runners.

return [

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'tsx',
        ],

    ],

];
