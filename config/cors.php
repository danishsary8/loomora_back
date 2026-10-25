<?php

/*
| The API is called from the frontend (a different domain) with bearer tokens,
| never cookies. Only the origin(s) in FRONTEND_URL may call it. Several
| origins can be given, comma-separated. When FRONTEND_URL is empty no
| browser origin is allowed.
*/

$origins = array_values(array_filter(array_map(
    fn (string $origin) => rtrim(trim($origin), '/'),
    explode(',', (string) env('FRONTEND_URL', '')),
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    // Bearer tokens only: browsers never need to send cookies.
    'supports_credentials' => false,

];
