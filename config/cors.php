<?php

/*
| AppServiceProvider sets the exact allowed origin from app.frontend_url.
| Auth uses bearer tokens, not cookies, so credentials stay off.
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
