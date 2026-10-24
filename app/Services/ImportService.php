<?php

namespace App\Services;

use App\Models\ScheduleCheckin;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes validated guest data (docs/08 rule 9) in one transaction.
 *
 * Rows keep the client's UUIDs. A row the user already has (same id, or same item and day for
 * exceptions and check-ins) is left untouched and counted as skipped, so sending the same
 * payload again creates nothing and never overwrites newer server edits.
 */
class ImportService
{
    public function __construct(private readonly StreakService $streak) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $data  already validated
     * @return array<string, array{created: int, skipped: int}>
     */
    public function import(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $result = [
                'todos' => $this->todos($user, $data['todos'] ?? []),
                'schedule_items' => $this->items($user, $data['schedule_items'] ?? []),
                'exceptions' => $this->exceptions($user, $data['exceptions'] ?? []),
                'checkins' => $this->checkins($user, $data['checkins'] ?? []),
            ];

            $this->streak->recompute($user);

            return $result;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function todos(User $user, array $rows): array
    {
        $existing = $user->todos()->whereIn('id', array_column($rows, 'id'))->pluck('id')->flip();
        $created = 0;

        foreach ($rows as $row) {
            if ($existing->has($row['id'])) {
                continue;
            }

            $todo = $this->fill(new Todo, $row, ['title', 'category', 'due_date', 'done_at']);
            $todo->category ??= 'other';
            $todo->user_id = $user->id;
            $this->keepCreatedAt($todo, $row);
            $todo->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => count($rows) - $created];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function items(User $user, array $rows): array
    {
        $existing = $user->scheduleItems()->whereIn('id', array_column($rows, 'id'))->pluck('id')->flip();
        $created = 0;

        foreach ($rows as $row) {
            if ($existing->has($row['id'])) {
                continue;
            }

            $item = $this->fill(new ScheduleItem, $row, ['title', 'category', 'start_time', 'end_time', 'starts_on', 'ends_on']);
            $item->category ??= 'other';
            $item->starts_on ??= now($user->timezone)->toDateString();
            $item->days = array_values(array_unique($row['days']));
            $item->user_id = $user->id;
            $this->keepCreatedAt($item, $row);
            $item->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => count($rows) - $created];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function exceptions(User $user, array $rows): array
    {
        $created = 0;

        foreach ($rows as $row) {
            $taken = ScheduleException::where('id', $row['id'])->exists()
                || ScheduleException::where('schedule_item_id', $row['schedule_item_id'])->where('date', $row['date'])->exists();
            if ($taken) {
                continue;
            }

            $override = $row['type'] === ScheduleException::OVERRIDE;
            $exception = $this->fill(new ScheduleException, $row, ['date', 'type']);
            if ($override) {
                $exception->title = $row['title'] ?? null;
                $exception->start_time = $row['start_time'] ?? null;
                $exception->end_time = $row['end_time'] ?? null;
            }
            $exception->schedule_item_id = $row['schedule_item_id'];
            $exception->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => count($rows) - $created];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function checkins(User $user, array $rows): array
    {
        $created = 0;

        foreach ($rows as $row) {
            $taken = ScheduleCheckin::where('id', $row['id'])->exists()
                || ScheduleCheckin::where('schedule_item_id', $row['schedule_item_id'])->where('date', $row['date'])->exists();
            if ($taken) {
                continue;
            }

            $checkin = $this->fill(new ScheduleCheckin, $row, ['date']);
            $checkin->done_at = $row['done_at'] ?? now();
            $checkin->schedule_item_id = $row['schedule_item_id'];
            $checkin->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => count($rows) - $created];
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $row
     */
    private function fill(Model $model, array $row, array $fields): Model
    {
        $model->id = $row['id'];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $model->{$field} = $row[$field];
            }
        }

        return $model;
    }

    /** @param  array<string, mixed>  $row */
    private function keepCreatedAt(Model $model, array $row): void
    {
        if (! empty($row['created_at'])) {
            $model->created_at = $row['created_at'];
        }
    }
}
