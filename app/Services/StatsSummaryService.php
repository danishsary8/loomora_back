<?php

namespace App\Services;

use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;

/**
 * @phpstan-type Metrics array{focus_ms: int, break_ms: int, sessions: int, todos_done: int, todos_due: int, planned_block_minutes: int, blocks_checked: int, focus_ms_linked: int, focus_ms_unplanned: int}
 */
class StatsSummaryService
{
    private const EMPTY_METRICS = [
        'focus_ms' => 0,
        'break_ms' => 0,
        'sessions' => 0,
        'todos_done' => 0,
        'todos_due' => 0,
        'planned_block_minutes' => 0,
        'blocks_checked' => 0,
        'focus_ms_linked' => 0,
        'focus_ms_unplanned' => 0,
    ];

    public function __construct(private readonly ScheduleService $schedule, private readonly StreakService $streak) {}

    /** @return array{opening_streak: int, opening_streak_may_be_longer: bool, days: list<array<string, int|string|bool>>, totals: Metrics, by_mode: array<string, int>} */
    public function summarize(User $user, string $from, string $to): array
    {
        $days = [];
        foreach ($this->schedule->forRange($user, $from, $to) as $date => $occurrences) {
            $day = self::EMPTY_METRICS;
            foreach ($occurrences as $occurrence) {
                $day['planned_block_minutes'] += $this->minutesSinceMidnight($occurrence['end_time'])
                    - $this->minutesSinceMidnight($occurrence['start_time']);
                $day['blocks_checked'] += (int) $occurrence['done'];
            }
            $days[$date] = $day;
        }

        $byMode = ['countdown' => 0, 'stopwatch' => 0];
        $sessions = $user->focusSessions()
            ->whereBetween('local_date', [$from, $to])
            ->selectRaw('local_date, mode, COUNT(*) AS session_count, SUM(focus_ms) AS focus_total, SUM(break_ms) AS break_total')
            ->selectRaw('SUM(CASE WHEN todo_id IS NOT NULL OR schedule_item_id IS NOT NULL THEN focus_ms ELSE 0 END) AS linked_total')
            ->groupBy('local_date', 'mode')
            ->toBase()
            ->get();

        foreach ($sessions as $session) {
            $date = $session->local_date;
            $focus = (int) $session->focus_total;
            $linked = (int) $session->linked_total;
            $days[$date]['focus_ms'] += $focus;
            $days[$date]['break_ms'] += (int) $session->break_total;
            $days[$date]['sessions'] += (int) $session->session_count;
            $days[$date]['focus_ms_linked'] += $linked;
            $days[$date]['focus_ms_unplanned'] += $focus - $linked;
            $byMode[$session->mode] += $focus;
        }

        $start = LocalDate::startOfDay($user, $from)->utc();
        $end = LocalDate::startOfDay($user, $to)->addDay()->startOfDay()->utc();
        $todos = $user->todos()->where(fn ($query) => $query
            ->whereBetween('due_date', [$from, $to])
            ->orWhere(fn ($query) => $query->whereNotNull('done_at')
                // A local midnight can cross the UTC bounds of the API's four-digit years.
                ->when($start->year > 0, fn ($query) => $query->where('done_at', '>=', $start))
                ->when($end->year <= 9999, fn ($query) => $query->where('done_at', '<', $end))))
            ->cursor();

        foreach ($todos as $todo) {
            $dueDate = $todo->due_date?->toDateString();
            if ($dueDate !== null && isset($days[$dueDate])) {
                $days[$dueDate]['todos_due']++;
            }
            if ($todo->done_at !== null) {
                $doneDate = LocalDate::forUser($user, $todo->done_at);
                if (isset($days[$doneDate])) {
                    $days[$doneDate]['todos_done']++;
                }
            }
        }

        $history = $this->streak->history($user, $from, $to);
        $opening = $history[CarbonImmutable::parse($from)->subDay()->toDateString()];
        $totals = self::EMPTY_METRICS;
        $rows = [];
        foreach ($days as $date => $day) {
            foreach ($day as $metric => $value) {
                $totals[$metric] += $value;
            }
            $rows[] = ['local_date' => $date, ...$day, ...$history[$date]];
        }

        return ['opening_streak' => $opening['streak'], 'opening_streak_may_be_longer' => $opening['streak_may_be_longer'], 'days' => $rows, 'totals' => $totals, 'by_mode' => $byMode];
    }

    private function minutesSinceMidnight(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
