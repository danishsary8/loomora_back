<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\DayGroup;
use App\Models\TimelineEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ScheduleMutationService
{
    public function __construct(private readonly ScheduleCache $cache, private readonly AnalyticsService $analytics) {}

    public function groups(User $user): Collection
    {
        return $user->dayGroups()->with($this->relations())->orderBy('sort_order')->get();
    }

    public function createGroup(User $user, array $data): DayGroup
    {
        $isFirstGroup = ! $user->dayGroups()->exists();
        $group = DB::transaction(function () use ($user, $data): DayGroup {
            $group = $user->dayGroups()->create([
                'name' => trim($data['name']),
                'color' => $data['color'] ?? '#0F766E',
                'sort_order' => $data['sort_order'] ?? $user->dayGroups()->count(),
            ]);
            $group->syncWeekdays($data['weekdays']);

            return $group;
        });
        $this->cache->invalidate((int) $user->id);
        if ($isFirstGroup) {
            $this->analytics->firstDayGroupCreated((int) $user->id);
        }

        return $group->load($this->relations());
    }

    public function updateGroup(User $user, int $id, array $data): DayGroup
    {
        $group = $this->ownedGroup($user, $id);
        DB::transaction(function () use ($group, $data): void {
            $group->update(array_intersect_key($data, array_flip(['name', 'color', 'sort_order'])));
            if (array_key_exists('weekdays', $data)) {
                $group->syncWeekdays($data['weekdays']);
            }
        });
        $this->cache->invalidate((int) $user->id);

        return $group->fresh($this->relations());
    }

    public function deleteGroup(User $user, int $id): void
    {
        $this->ownedGroup($user, $id)->delete();
        $this->cache->invalidate((int) $user->id);
        $this->analytics->dayGroupDeleted();
    }

    public function entries(User $user, int $groupId): Collection
    {
        return $this->ownedGroup($user, $groupId)->timelineEntries()->orderBy('sort_order')->get();
    }

    public function createEntry(User $user, int $groupId, array $data): TimelineEntry
    {
        $group = $this->ownedGroup($user, $groupId);
        $entry = $group->timelineEntries()->create($data + ['sort_order' => $group->timelineEntries()->count()]);
        $this->cache->invalidate((int) $user->id);

        return $entry;
    }

    public function updateEntry(User $user, int $id, array $data): TimelineEntry
    {
        $entry = $this->ownedEntry($user, $id);
        $entry->update($data);
        $this->cache->invalidate((int) $user->id);

        return $entry->refresh();
    }

    public function deleteEntry(User $user, int $id): void
    {
        $this->ownedEntry($user, $id)->delete();
        $this->cache->invalidate((int) $user->id);
    }

    public function items(User $user, int $groupId): Collection
    {
        return $this->ownedGroup($user, $groupId)->checklistItems()->orderBy('sort_order')->get();
    }

    public function createItem(User $user, int $groupId, array $data): ChecklistItem
    {
        $group = $this->ownedGroup($user, $groupId);
        $item = $group->checklistItems()->create([
            'label' => trim($data['label']),
            'sort_order' => $data['sort_order'] ?? $group->checklistItems()->count(),
        ]);
        $this->cache->invalidate((int) $user->id);

        return $item;
    }

    public function updateItem(User $user, int $id, array $data): ChecklistItem
    {
        $item = $this->ownedItem($user, $id);
        if (isset($data['label'])) {
            $data['label'] = trim($data['label']);
        }
        $item->update($data);
        $this->cache->invalidate((int) $user->id);

        return $item->refresh();
    }

    public function deleteItem(User $user, int $id): void
    {
        $this->ownedItem($user, $id)->delete();
        $this->cache->invalidate((int) $user->id);
    }

    private function ownedGroup(User $user, int $id): DayGroup
    {
        return $user->dayGroups()->findOrFail($id);
    }

    private function ownedEntry(User $user, int $id): TimelineEntry
    {
        return TimelineEntry::whereKey($id)->whereHas('dayGroup', fn ($query) => $query->where('user_id', $user->id))->firstOrFail();
    }

    private function ownedItem(User $user, int $id): ChecklistItem
    {
        return ChecklistItem::whereKey($id)->whereHas('dayGroup', fn ($query) => $query->where('user_id', $user->id))->firstOrFail();
    }

    private function relations(): array
    {
        return ['weekdays' => fn ($query) => $query->orderBy('weekday'), 'timelineEntries' => fn ($query) => $query->orderBy('sort_order'), 'checklistItems' => fn ($query) => $query->orderBy('sort_order')];
    }
}
