<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnalyticsEvent;
use Illuminate\Database\QueryException;

final class AnalyticsService
{
    private const PAGE_PATHS = ['/', '/login', '/register', '/password/forgot', '/password/reset', '/auth/google/callback', '/email-verified', '/privacy', '/terms', '/dashboard', '/internal/analytics'];

    public const CLIENT_EVENTS = [
        'notification_permission_granted',
        'notification_permission_denied',
    ];

    public function pageView(string $path, ?string $dedupeKey = null): void
    {
        $this->record('page_view', [], $dedupeKey, $this->sanitizePath($path));
    }

    public function clientEvent(string $name, ?string $dedupeKey = null): void
    {
        $this->record($name, [], $dedupeKey);
    }

    public function signupCompleted(string $method, int $userId): void
    {
        $this->record('signup_completed', ['method' => $method], $this->serverKey('signup_completed', $userId));
    }

    public function firstDayGroupCreated(int $userId): void
    {
        $this->record('first_day_group_created', [], $this->serverKey('first_day_group_created', $userId));
    }

    public function checklistItemsChecked(int $count): void
    {
        for ($event = 0; $event < $count; $event++) {
            $this->record('checklist_item_checked');
        }
    }

    public function dayGroupDeleted(): void
    {
        $this->record('day_group_deleted');
    }

    public function accountDeleted(int $userId): void
    {
        $this->record('account_deleted', [], $this->serverKey('account_deleted', $userId));
    }

    private function record(string $name, array $properties = [], ?string $dedupeKey = null, ?string $path = null): void
    {
        if (! config('analytics.enabled')) {
            return;
        }

        try {
            AnalyticsEvent::create([
                'event_name' => $name,
                'path' => $path,
                'properties' => $properties === [] ? null : $properties,
                'dedupe_key' => $dedupeKey === null ? null : hash('sha256', $dedupeKey),
                'occurred_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // A repeated idempotency key is an expected retry, not a second occurrence.
            if ($dedupeKey === null || ! str_contains(strtolower($exception->getMessage()), 'unique')) {
                report($exception);
            }
        }
    }

    private function serverKey(string $event, int $userId): string
    {
        // Event-specific HMAC prevents IDs being recovered or correlated across event types.
        return hash_hmac('sha256', $event.':'.$userId, (string) config('app.key'));
    }

    private function sanitizePath(string $path): string
    {
        $normalized = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');

        return in_array($normalized, self::PAGE_PATHS, true) ? $normalized : '/other';
    }
}
