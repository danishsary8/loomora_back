<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single place that turns an instant into the user's local calendar date (and back).
 * Every "day" in Loomora is the user's local date, computed on the server.
 */
final class LocalDate
{
    /** The local date (Y-m-d) of an instant in the user's time zone. */
    public static function forUser(User $user, CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($user->timezone)->toDateString();
    }

    /** Today's local date (Y-m-d) for the user. */
    public static function todayFor(User $user): string
    {
        return self::forUser($user, CarbonImmutable::now());
    }

    /** Midnight at the start of a local date (Y-m-d), in the user's time zone. */
    public static function startOfDay(User $user, string $localDate): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $user->timezone);
    }

    /**
     * The first and last moment of a local date, in UTC. A day with a daylight-saving change
     * spans 23 or 25 hours.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function rangeUtc(User $user, string $localDate): array
    {
        $start = self::startOfDay($user, $localDate);

        return [$start->utc(), $start->endOfDay()->utc()];
    }
}
