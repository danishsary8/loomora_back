<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ChecklistService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function forDate(User $user, Carbon $date): array
    {
        $groupIds = DB::table('day_group_weekdays')->where('weekday', (int) $date->dayOfWeek)->pluck('day_group_id');
        $items = ChecklistItem::whereIn('day_group_id', $groupIds)->whereHas('dayGroup', fn ($q) => $q->where('user_id', $user->id))->orderBy('sort_order')->get();
        $logs = ChecklistLog::where('user_id', $user->id)->whereDate('log_date', $date->toDateString())->pluck('is_checked', 'checklist_item_id');
        $resolved = $items->map(fn ($i) => ['id' => $i->id, 'label' => $i->label, 'is_checked' => (bool) ($logs[$i->id] ?? false)])->all();
        $checked = array_values(array_map(fn ($i) => (int) $i['id'], array_filter($resolved, fn ($i) => $i['is_checked'])));

        return ['date' => $date->toDateString(), 'items' => $resolved, 'checked_ids' => $checked, 'completed' => count($checked), 'total' => count($resolved)];
    }

    public function upsertForDate(User $user, Carbon $date, array $checkedIds): array
    {
        $owned = ChecklistItem::whereIn('id', $checkedIds)->whereHas('dayGroup', fn ($q) => $q->where('user_id', $user->id))->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (count(array_unique($checkedIds)) !== count($owned)) {
            abort(422, 'One or more checklist items do not belong to this account.');
        }
        $current = $this->forDate($user, $date);
        $newlyChecked = array_diff($owned, $current['checked_ids']);
        $ids = array_column($current['items'], 'id');
        DB::transaction(function () use ($user, $date, $ids, $owned) {
            foreach ($ids as $id) {
                ChecklistLog::updateOrCreate(['user_id' => $user->id, 'checklist_item_id' => $id, 'log_date' => $date->toDateString()], ['is_checked' => in_array($id, $owned, true)]);
            }
        });
        $this->analytics->checklistItemsChecked(count($newlyChecked));

        return $this->forDate($user, $date);
    }
}
