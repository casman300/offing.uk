<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains (Connect Claude, spec §12)
    |--------------------------------------------------------------------------
    |
    | Dynamic client registration is an open endpoint, so only these hosts may
    | register as OAuth clients: Claude on the web, desktop and mobile, plus
    | localhost for Claude Code. Self-hosters can allow other MCP clients
    | (ChatGPT, Cursor…) with MCP_REDIRECT_DOMAINS, comma-separated.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,http://localhost,http://127.0.0.1',
    ))))),

    'custom_schemes' => [],

    'authorization_server' => null,

];
