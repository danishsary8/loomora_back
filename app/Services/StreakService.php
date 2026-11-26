<?php

namespace App\Services;

use App\Models\ScheduleCheckin;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;

/**
 * Streak per docs/08 rule 8.
 *
 * - A day is "counted" when at least one to-do was checked off that local day (done_at in the
 *   user's time zone) or one schedule occurrence of that date was checked in.
 * - A day is "planned" when a to-do is due that day or the schedule has an occurrence on it.
 * - Walking back from yesterday: a counted day adds 1, a planned day with nothing counted
 *   breaks the streak, a day with nothing planned and nothing counted is skipped.
 * - Today is never judged: it adds 1 if counted, and otherwise changes nothing.
 *
 * It looks back at most WINDOW_DAYS days. Stored values are recomputed lazily, on every
 * check-off and on `GET /streak` (no scheduled job).
 */
class StreakService
{
    public const WINDOW_DAYS = 400;

    public function __construct(private readonly ScheduleService $schedule) {}

    /**
     * Recomputes and stores current_streak, longest_streak and last_streak_date.
     *
     * @return array{current_streak: int, longest_streak: int, today_counted: bool}
     */
    public function recompute(User $user, ?string $today = null): array
    {
        $today ??= LocalDate::todayFor($user);
        $result = $this->calculate($user, $today);

        $user->forceFill([
            'current_streak' => $result['current'],
            'longest_streak' => max($user->longest_streak, $result['current']),
            'last_streak_date' => $result['last_date'],
        ])->save();

        return [
            'current_streak' => $user->current_streak,
            'longest_streak' => $user->longest_streak,
            'today_counted' => $result['today_counted'],
        ];
    }

    /**
     * @return array{current: int, last_date: string|null, today_counted: bool}
     */
    public function calculate(User $user, string $today): array
    {
        $todayDate = CarbonImmutable::parse($today);
        $from = $todayDate->subDays(self::WINDOW_DAYS)->toDateString();

        $counted = $this->countedDates($user, $from, $today);
        $planned = $this->plannedDates($user, $from, $today);

        $streak = 0;
        $lastDate = null;

        for ($day = $todayDate->subDay(); $day->toDateString() >= $from; $day = $day->subDay()) {
            $date = $day->toDateString();

            if (isset($counted[$date])) {
                $streak++;
                $lastDate ??= $date;
            } elseif (isset($planned[$date])) {
                break;
            }
        }

        $todayCounted = isset($counted[$today]);
        if ($todayCounted) {
            $streak++;
            $lastDate = $today;
        }

        return ['current' => $streak, 'last_date' => $lastDate, 'today_counted' => $todayCounted];
    }

    /**
     * @return array<string, array{streak: int, streak_may_be_longer: bool}>
     */
    public function history(User $user, string $from, string $to): array
    {
        $first = CarbonImmutable::parse($from);
        $earliest = $first->subDays(self::WINDOW_DAYS + 1);
        $calendarStart = CarbonImmutable::parse('0001-01-01');
        if ($earliest->lt($calendarStart)) {
            $earliest = $calendarStart;
        }

        $counted = $this->countedDates($user, $earliest->toDateString(), $to);
        $planned = $this->plannedDates($user, $earliest->toDateString(), $to);
        $today = LocalDate::todayFor($user);
        $result = [];

        for ($date = $first->subDay(); $date->lte(CarbonImmutable::parse($to)); $date = $date->addDay()) {
            if ($date->lt($calendarStart)) {
                $result[$date->toDateString()] = ['streak' => 0, 'streak_may_be_longer' => false];

                continue;
            }

            $value = 0;
            $mayBeLonger = true;
            $start = $date->subDays(self::WINDOW_DAYS);
            if ($start->lt($calendarStart)) {
                $start = $calendarStart;
            }
            for ($day = $date; $day->gte($start); $day = $day->subDay()) {
                $key = $day->toDateString();
                if (isset($counted[$key])) {
                    $value++;
                } elseif ($key !== $today && isset($planned[$key])) {
                    $mayBeLonger = false;
                    break;
                }
            }
            if ($start->equalTo($calendarStart) && $day->lt($calendarStart)) {
                $mayBeLonger = false;
            }
            $result[$date->toDateString()] = ['streak' => $value, 'streak_may_be_longer' => $mayBeLonger];
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function countedDates(User $user, string $from, string $to): array
    {
        $dates = [];

        // Look one day further back in UTC so no local day at the edge is cut off.
        $lowerBound = LocalDate::rangeUtc($user, $from)[0]->subDay();
        $user->todos()
            ->whereNotNull('done_at')
            ->when($lowerBound->year > 0, fn ($query) => $query->where('done_at', '>=', $lowerBound))
            ->pluck('done_at')
            ->each(function ($doneAt) use (&$dates, $user) {
                $dates[LocalDate::forUser($user, CarbonImmutable::parse($doneAt))] = true;
            });

        ScheduleCheckin::query()
            ->whereIn('schedule_item_id', $user->scheduleItems()->select('id'))
            ->whereBetween('date', [$from, $to])
            ->distinct()
            ->pluck('date')
            ->each(function ($date) use (&$dates) {
                $dates[substr((string) $date, 0, 10)] = true;
            });

        return $dates;
    }

    /**
     * @return array<string, true>
     */
    private function plannedDates(User $user, string $from, string $to): array
    {
        $dates = [];

        $user->todos()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from, $to])
            ->distinct()
            ->pluck('due_date')
            ->each(function ($date) use (&$dates) {
                $dates[substr((string) $date, 0, 10)] = true;
            });

        foreach ($this->schedule->forRange($user, $from, $to) as $date => $occurrences) {
            if ($occurrences !== []) {
                $dates[$date] = true;
            }
        }

        return $dates;
    }
}
