<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\ChecklistLog;
use App\Models\DayGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AnalyticsReportService
{
    public function summary(): array
    {
        $since = now()->subDays(7)->startOfDay();
        $checklist = ChecklistLog::query()
            ->where('log_date', '>=', $since->toDateString())
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN is_checked THEN 1 ELSE 0 END) AS completed')
            ->first();
        $total = (int) ($checklist?->total ?? 0);
        $completed = (int) ($checklist?->completed ?? 0);

        return [
            'total_users' => User::query()->count(),
            'total_day_groups' => DayGroup::query()->count(),
            'checklist_last_7_days' => [
                'completed' => $completed,
                'total' => $total,
                'completion_rate' => $total === 0 ? 0.0 : round(($completed / $total) * 100, 1),
            ],
            'events_last_30_days' => AnalyticsEvent::query()
                ->where('occurred_at', '>=', now()->subDays(30))
                ->select('event_name', DB::raw('COUNT(*) AS total'))
                ->groupBy('event_name')
                ->orderBy('event_name')
                ->pluck('total', 'event_name')
                ->map(fn ($count): int => (int) $count)
                ->all(),
            'pageviews_last_30_days' => AnalyticsEvent::query()
                ->where('event_name', 'page_view')
                ->where('occurred_at', '>=', now()->subDays(30))
                ->select('path', DB::raw('COUNT(*) AS total'))
                ->groupBy('path')
                ->orderByDesc('total')
                ->pluck('total', 'path')
                ->map(fn ($count): int => (int) $count)
                ->all(),
        ];
    }
}
