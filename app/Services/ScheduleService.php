<?php

namespace App\Services;

use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns repeat definitions into concrete occurrences (docs/08 rules 4, 6 and 7).
 *
 * An occurrence is a plain array:
 * item_id, date, title, category, start_time, end_time (HH:MM), done, overridden.
 */
class ScheduleService
{
    /** Weekday number used by the API: 0 = Monday ... 6 = Sunday. */
    public static function weekday(CarbonImmutable|string $date): int
    {
        return CarbonImmutable::parse($date)->dayOfWeekIso - 1;
    }

    /** Rule 4 before exceptions: does the repeat definition itself match this date? */
    public function matchesRule(ScheduleItem $item, string $date): bool
    {
        return $date >= $item->starts_on->toDateString()
            && ($item->ends_on === null || $date <= $item->ends_on->toDateString())
            && in_array(self::weekday($date), $item->days, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forDate(User $user, string $date): array
    {
        return $this->forRange($user, $date, $date)[$date];
    }

    /**
     * Occurrences for every date from $from to $to (inclusive), keyed by date.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function forRange(User $user, string $from, string $to): array
    {
        $items = $user->scheduleItems()
            ->where('starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))
            ->with([
                'exceptions' => fn ($q) => $q->whereBetween('date', [$from, $to]),
                'checkins' => fn ($q) => $q->whereBetween('date', [$from, $to]),
            ])
            ->get();

        $result = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            $result[$date] = [];

            foreach ($items as $item) {
                if (! $this->matchesRule($item, $date)) {
                    continue;
                }

                /** @var ScheduleException|null $exception */
                $exception = $item->exceptions->firstWhere('date', $date);
                if ($exception?->type === ScheduleException::SKIP) {
                    continue;
                }

                $result[$date][] = $this->occurrence($item, $date, $exception, $item->checkins->contains('date', $date));
            }

            usort($result[$date], fn ($a, $b) => [$a['start_time'], $a['title']] <=> [$b['start_time'], $b['title']]);
        }

        return $result;
    }

    /**
     * Rule 6. $time is the current local time (HH:MM). Both values are null when there is
     * nothing to show ("Free" / "Nothing more today" are display text for the client).
     *
     * @return array{now: array<string, mixed>|null, next: array<string, mixed>|null}
     */
    public function nowAndNext(User $user, string $date, string $time): array
    {
        $now = null;
        $next = null;

        foreach ($this->forDate($user, $date) as $occurrence) {
            if ($occurrence['start_time'] <= $time && $time < $occurrence['end_time']) {
                $now = $occurrence; // sorted by start, so the latest-starting overlap wins
            } elseif ($occurrence['start_time'] > $time && $next === null) {
                $next = $occurrence;
            }
        }

        return ['now' => $now, 'next' => $next];
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrence(ScheduleItem $item, string $date, ?ScheduleException $exception, bool $done): array
    {
        return [
            'item_id' => $item->id,
            'date' => $date,
            'title' => $exception?->title ?? $item->title,
            'category' => $item->category,
            'start_time' => ScheduleItem::hhmm($exception?->start_time ?? $item->start_time),
            'end_time' => ScheduleItem::hhmm($exception?->end_time ?? $item->end_time),
            'done' => $done,
            'overridden' => $exception !== null,
        ];
    }
}
