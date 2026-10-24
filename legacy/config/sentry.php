<?php

declare(strict_types=1);

use App\Monitoring\SentryEventScrubber;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
    'release' => env('SENTRY_RELEASE'),
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.05),
    'send_default_pii' => false,
    'enable_logs' => false,
    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'http_client_requests' => true,
        'notifications' => false,
    ],
    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'http_client_requests' => true,
        'cache' => true,
        'redis_commands' => false,
        'default_integrations' => true,
    ],
    'ignore_transactions' => ['/up', '/api/v1/health'],
    'before_send' => [SentryEventScrubber::class, 'handle'],
];
