<?php

/*
| Only the Loomora web app may call the API from a browser. FRONTEND_URL can
| hold several origins separated by commas (e.g. production and preview).
| Auth uses bearer tokens, not cookies, so credentials stay off.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', (string) env('FRONTEND_URL', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
