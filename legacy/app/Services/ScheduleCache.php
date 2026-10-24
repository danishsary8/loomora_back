<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ScheduleCache
{
    private const TTL_SECONDS = 60;

    /** @param Closure(): array<string, mixed> $resolver */
    public function remember(int $userId, Carbon $date, Closure $resolver): array
    {
        $version = Cache::rememberForever($this->versionKey($userId), fn (): string => (string) Str::uuid());
        $key = "schedule:today:user:{$userId}:version:{$version}:date:{$date->toDateString()}";

        /** @var array<string, mixed> */
        return Cache::remember($key, self::TTL_SECONDS, $resolver);
    }

    public function invalidate(int $userId): void
    {
        Cache::forever($this->versionKey($userId), (string) Str::uuid());
    }

    private function versionKey(int $userId): string
    {
        return "schedule:version:user:{$userId}";
    }
}
