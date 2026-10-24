<?php

declare(strict_types=1);

namespace Tests\Unit;

use Sentry\Event;
use Tests\TestCase;

final class MonitoringPrivacyTest extends TestCase
{
    public function test_sentry_scrubber_removes_sensitive_request_material(): void
    {
        $event = Event::createEvent()->setRequest([
            'url' => 'https://api.example.test/api/v1/password/reset',
            'query_string' => 'token=secret&email=user@example.test',
            'data' => ['password' => 'Secret123', 'token' => 'secret'],
            'cookies' => ['session' => 'secret'],
            'headers' => ['Authorization' => 'Bearer secret', 'X-XSRF-TOKEN' => 'secret', 'Accept' => 'application/json'],
        ]);

        $scrubber = config('sentry.before_send');
        $request = call_user_func($scrubber, $event)->getRequest();

        self::assertArrayNotHasKey('query_string', $request);
        self::assertArrayNotHasKey('data', $request);
        self::assertArrayNotHasKey('cookies', $request);
        self::assertSame('[Filtered]', $request['headers']['Authorization']);
        self::assertSame('[Filtered]', $request['headers']['X-XSRF-TOKEN']);
        self::assertSame('application/json', $request['headers']['Accept']);
    }
}
