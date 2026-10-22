<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains (Connect Claude, spec §12)
    |--------------------------------------------------------------------------
    |
    | Dynamic client registration is an open endpoint, so only these hosts may
    | register as OAuth clients: Claude and ChatGPT (web, desktop, mobile), and
    | localhost for Claude Code and other desktop clients. Matched as URL
    | prefixes. Add more with MCP_REDIRECT_DOMAINS, comma-separated.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai,https://claude.com,https://chatgpt.com,http://localhost,http://127.0.0.1',
    ))))),

    'custom_schemes' => [],

    'authorization_server' => null,

];
