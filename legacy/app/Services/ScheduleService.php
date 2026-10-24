<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DayGroup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ScheduleService
{
    public function __construct(private readonly ScheduleCache $cache) {}

    public function today(User $user, ?Carbon $date = null): array
    {
        $date ??= Carbon::now();

        return $this->cache->remember($user->id, $date, fn (): array => $this->resolveToday($user, $date));
    }

    private function resolveToday(User $user, Carbon $date): array
    {
        $weekday = (int) $date->dayOfWeek;
        $ids = DB::table('day_group_weekdays')->where('weekday', $weekday)->pluck('day_group_id');
        $groups = $user->dayGroups()->whereIn('id', $ids)->with(['weekdays' => fn ($q) => $q->orderBy('weekday'), 'timelineEntries' => fn ($q) => $q->orderBy('sort_order'), 'checklistItems' => fn ($q) => $q->orderBy('sort_order')])->orderBy('sort_order')->get()->map(fn (DayGroup $g) => $this->shape($g))->all();

        return ['date' => $date->toDateString(), 'weekday' => $weekday, 'assigned' => $groups !== [], 'message' => $groups === [] ? 'No day group is assigned to this weekday.' : null, 'groups' => $groups, 'day_group' => $groups[0] ?? null, 'timeline' => collect($groups)->flatMap(fn ($g) => $g['timeline'])->values()->all(), 'checklist' => collect($groups)->flatMap(fn ($g) => $g['checklist'])->values()->all()];
    }

    public function group(User $user, int $id): array
    {
        $group = $user->dayGroups()->with(['weekdays' => fn ($q) => $q->orderBy('weekday'), 'timelineEntries' => fn ($q) => $q->orderBy('sort_order'), 'checklistItems' => fn ($q) => $q->orderBy('sort_order')])->findOrFail($id);

        return $this->shape($group);
    }

    private function shape(DayGroup $g): array
    {
        return ['id' => $g->id, 'name' => $g->name, 'color' => $g->color, 'sort_order' => $g->sort_order, 'weekdays' => $g->weekdayValues(), 'timeline' => $g->timelineEntries->map(fn ($e) => ['id' => $e->id, 'start' => $e->startHm(), 'end' => $e->endHm(), 'description' => $e->description, 'category' => $e->category->value, 'sort_order' => $e->sort_order])->all(), 'checklist' => $g->checklistItems->map(fn ($i) => ['id' => $i->id, 'label' => $i->label, 'sort_order' => $i->sort_order])->all()];
    }
}
