<?php

declare(strict_types=1);

namespace App\Monitoring;

use Sentry\Event;
use Sentry\UserDataBag;

final class SentryEventScrubber
{
    public static function handle(Event $event): Event
    {
        $request = $event->getRequest();
        unset($request['data'], $request['cookies']);

        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach (array_keys($request['headers']) as $header) {
                if (in_array(strtolower((string) $header), ['authorization', 'cookie', 'x-xsrf-token'], true)) {
                    $request['headers'][$header] = '[Filtered]';
                }
            }
        }

        unset($request['query_string']);
        $event->setRequest($request);

        $userId = auth()->id();
        $event->setUser($userId === null ? null : UserDataBag::createFromUserIdentifier((string) $userId));

        return $event;
    }
}
