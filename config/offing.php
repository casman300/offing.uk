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

];
