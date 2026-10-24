<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UpstreamServiceException;
use App\Models\PrayerTimeCache;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side prayer-time resolution.
 *
 * Fetching Aladhan here (rather than from the browser) keeps third-party
 * rate-limit exposure and coordinate handling on the server, and lets us cache
 * one row per user per calendar day.
 */
final class PrayerTimeService
{
    private const PRAYERS = ['Fajr', 'Dhuhr', 'Asr', 'Maghrib', 'Isha'];

    /**
     * @return array<string, mixed>
     */
    public function forDate(User $user, Carbon $date, float $latitude, float $longitude): array
    {
        $cached = PrayerTimeCache::query()
            ->where('user_id', $user->id)
            ->whereDate('cache_date', $date->toDateString())
            ->first();

        // Only reuse the cache when it was built for materially the same place.
        // Without this check a user who moves (or corrects their coordinates)
        // keeps getting the previous location's times for the rest of the day.
        if ($cached instanceof PrayerTimeCache && $this->sameLocation($cached, $latitude, $longitude)) {
            return $this->present($cached, 'cache');
        }

        // These are public calculations, not user records. Sharing at
        // two-decimal coordinate precision (~1.1 km) avoids duplicate upstream
        // calls for nearby users without exposing one user's location to another.
        $roundedLatitude = round($latitude, 2);
        $roundedLongitude = round($longitude, 2);
        $sharedKey = sprintf(
            'prayer:shared:%s:%.2f:%.2f:method:%d',
            $date->toDateString(),
            $roundedLatitude,
            $roundedLongitude,
            (int) config('services.aladhan.method'),
        );
        $wasShared = Cache::has($sharedKey);
        $timings = Cache::remember(
            $sharedKey,
            now()->addHours(24),
            fn (): array => $this->fetchFromUpstream($date, $roundedLatitude, $roundedLongitude),
        );

        $record = PrayerTimeCache::updateOrCreate(
            ['user_id' => $user->id, 'cache_date' => $date->toDateString()],
            [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'fajr' => $timings['Fajr'],
                'dhuhr' => $timings['Dhuhr'],
                'asr' => $timings['Asr'],
                'maghrib' => $timings['Maghrib'],
                'isha' => $timings['Isha'],
            ],
        );

        return $this->present($record, $wasShared ? 'shared-cache' : 'api');
    }

    /**
     * Coordinates are considered equivalent within ~1km (0.01 degrees), which
     * is far below the resolution that would shift a prayer time by a minute.
     */
    private function sameLocation(PrayerTimeCache $cached, float $latitude, float $longitude): bool
    {
        return abs($cached->latitude - $latitude) < 0.01
            && abs($cached->longitude - $longitude) < 0.01;
    }

    /**
     * @return array<string, string>
     */
    private function fetchFromUpstream(Carbon $date, float $latitude, float $longitude): array
    {
        $baseUrl = rtrim((string) config('services.aladhan.base_url'), '/');
        $method = (int) config('services.aladhan.method');

        try {
            $response = Http::timeout((int) config('services.aladhan.timeout'))
                ->retry(2, 200)
                ->acceptJson()
                ->get("{$baseUrl}/timings/{$date->format('d-m-Y')}", [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'method' => $method,
                ]);
        } catch (Throwable $e) {
            throw new UpstreamServiceException('Could not reach the prayer-time provider.', $e);
        }

        if ($response->failed()) {
            throw new UpstreamServiceException('The prayer-time provider returned an error.');
        }

        /** @var array<string, string>|null $timings */
        $timings = $response->json('data.timings');

        if (! is_array($timings)) {
            throw new UpstreamServiceException('The prayer-time provider returned an unexpected payload.');
        }

        $normalised = [];

        foreach (self::PRAYERS as $prayer) {
            $value = $this->normaliseTime($timings[$prayer] ?? null);

            if ($value === null) {
                throw new UpstreamServiceException("Missing or invalid time for {$prayer}.");
            }

            $normalised[$prayer] = $value;
        }

        return $normalised;
    }

    /**
     * Aladhan may return "04:32" or "04:32 (+07)" — reduce to "HH:MM".
     */
    private function normaliseTime(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        if (preg_match('/(\d{1,2}):(\d{2})/', $raw, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%s', $hour, $matches[2]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PrayerTimeCache $record, string $source): array
    {
        return [
            'date' => $record->cache_date->toDateString(),
            'latitude' => $record->latitude,
            'longitude' => $record->longitude,
            'timings' => $record->timings(),
            'source' => $source,
        ];
    }
}
