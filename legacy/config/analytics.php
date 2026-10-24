<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ANALYTICS_ENABLED', true),
    'admin_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => strtolower(trim($email)),
        explode(',', (string) env('ANALYTICS_ADMIN_EMAILS', '')),
    ))),
];
