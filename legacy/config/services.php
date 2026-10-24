<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
    | Aladhan prayer-time API. Keyless, but proxied server-side so the browser
    | never talks to it directly. Method 2 = ISNA (matches the frontend).
    */
    'aladhan' => [
        'base_url' => env('ALADHAN_BASE_URL', 'https://api.aladhan.com/v1'),
        'method' => env('ALADHAN_METHOD', 2),
        'timeout' => env('ALADHAN_TIMEOUT', 8),
        'default_latitude' => env('PRAYER_DEFAULT_LATITUDE', 11.5564),
        'default_longitude' => env('PRAYER_DEFAULT_LONGITUDE', 104.9282),
    ],

];
