<?php

namespace App\Http\Requests\Import;

use App\Models\ScheduleCheckin;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Models\Todo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guest data import (docs/08 rule 9). Every row carries the UUID the client gave it.
 * All checks run before anything is written.
 */
class ImportRequest extends FormRequest
{
    /** Payload size caps (the R1 per-user limits for to-dos and items, sane caps for the rest). */
    public const MAX_EXCEPTIONS = 5000;

    public const MAX_CHECKINS = 10000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'todos' => ['sometimes', 'array', 'max:'.Todo::MAX_PER_USER],
            'todos.*.id' => ['required', 'uuid', 'distinct'],
            'todos.*.title' => ['required', 'string', 'min:1', 'max:200'],
            'todos.*.category' => ['sometimes', 'string', Rule::in(Todo::CATEGORIES)],
            'todos.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'todos.*.done_at' => ['nullable', 'date'],
            'todos.*.created_at' => ['nullable', 'date'],

            'schedule_items' => ['sometimes', 'array', 'max:'.ScheduleItem::MAX_PER_USER],
            'schedule_items.*.id' => ['required', 'uuid', 'distinct'],
            'schedule_items.*.title' => ['required', 'string', 'min:1', 'max:200'],
            'schedule_items.*.category' => ['sometimes', 'string', Rule::in(ScheduleItem::CATEGORIES)],
            'schedule_items.*.start_time' => ['required', 'date_format:H:i'],
            'schedule_items.*.end_time' => ['required', 'date_format:H:i'],
            'schedule_items.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'schedule_items.*.days.*' => ['integer', 'distinct', 'between:0,6'],
            'schedule_items.*.starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'schedule_items.*.ends_on' => ['nullable', 'date_format:Y-m-d'],
            'schedule_items.*.created_at' => ['nullable', 'date'],

            'exceptions' => ['sometimes', 'array', 'max:'.self::MAX_EXCEPTIONS],
            'exceptions.*.id' => ['required', 'uuid', 'distinct'],
            'exceptions.*.schedule_item_id' => ['required', 'uuid'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.type' => ['required', Rule::in([ScheduleException::SKIP, ScheduleException::OVERRIDE])],
            'exceptions.*.title' => ['nullable', 'string', 'min:1', 'max:200'],
            'exceptions.*.start_time' => ['nullable', 'date_format:H:i'],
            'exceptions.*.end_time' => ['nullable', 'date_format:H:i'],

            'checkins' => ['sometimes', 'array', 'max:'.self::MAX_CHECKINS],
            'checkins.*.id' => ['required', 'uuid', 'distinct'],
            'checkins.*.schedule_item_id' => ['required', 'uuid'],
            'checkins.*.date' => ['required', 'date_format:Y-m-d'],
            'checkins.*.done_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkTimesAndDates($validator);
                $this->checkReferencesAndDuplicates($validator);
                $this->checkOwnership($validator);

                if ($validator->errors()->isEmpty()) {
                    $this->checkLimits($validator);
                }
            },
        ];
    }

    private function checkTimesAndDates(Validator $validator): void
    {
        foreach ($this->input('schedule_items', []) as $i => $item) {
            if ($item['start_time'] >= $item['end_time']) {
                $validator->errors()->add("schedule_items.{$i}.end_time", __('schedule.end_after_start'));
            }
            if (! empty($item['ends_on']) && isset($item['starts_on']) && $item['ends_on'] < $item['starts_on']) {
                $validator->errors()->add("schedule_items.{$i}.ends_on", __('schedule.ends_after_starts'));
            }
        }

        foreach ($this->input('exceptions', []) as $i => $exception) {
            if ($exception['type'] !== ScheduleException::OVERRIDE) {
                continue;
            }
            if (empty($exception['title']) && empty($exception['start_time']) && empty($exception['end_time'])) {
                $validator->errors()->add("exceptions.{$i}.type", __('schedule.override_needs_change'));
            }
            if (! empty($exception['start_time']) && ! empty($exception['end_time']) && $exception['start_time'] >= $exception['end_time']) {
                $validator->errors()->add("exceptions.{$i}.end_time", __('schedule.end_after_start'));
            }
        }
    }

    /** Exceptions and check-ins must point at an item in this payload or one the user already has. */
    private function checkReferencesAndDuplicates(Validator $validator): void
    {
        /** @var list<array<string, mixed>> $items */
        $items = $this->input('schedule_items', []);
        $known = collect($items)->pluck('id')
            ->merge($this->user()->scheduleItems()->pluck('id'))->flip();

        foreach (['exceptions', 'checkins'] as $key) {
            $seen = [];
            foreach ($this->input($key, []) as $i => $row) {
                if (! $known->has($row['schedule_item_id'])) {
                    $validator->errors()->add("{$key}.{$i}.schedule_item_id", __('import.unknown_item'));
                }

                $pair = $row['schedule_item_id'].'|'.$row['date'];
                if (isset($seen[$pair])) {
                    $validator->errors()->add("{$key}.{$i}.date", __('import.duplicate_day'));
                }
                $seen[$pair] = true;
            }
        }
    }

    /** A client id that already belongs to someone else must never be overwritten or reused. */
    private function checkOwnership(Validator $validator): void
    {
        $userId = $this->user()->id;

        $foreign = [
            'todos' => fn (array $ids) => Todo::whereIn('id', $ids)->where('user_id', '!=', $userId)->pluck('id'),
            'schedule_items' => fn (array $ids) => ScheduleItem::whereIn('id', $ids)->where('user_id', '!=', $userId)->pluck('id'),
            'exceptions' => fn (array $ids) => ScheduleException::whereIn('id', $ids)
                ->whereHas('scheduleItem', fn ($q) => $q->where('user_id', '!=', $userId))->pluck('id'),
            'checkins' => fn (array $ids) => ScheduleCheckin::whereIn('id', $ids)
                ->whereHas('scheduleItem', fn ($q) => $q->where('user_id', '!=', $userId))->pluck('id'),
        ];

        foreach ($foreign as $key => $lookup) {
            $rows = $this->input($key, []);
            $taken = $lookup(array_column($rows, 'id'))->flip();

            foreach ($rows as $i => $row) {
                if ($taken->has($row['id'])) {
                    $validator->errors()->add("{$key}.{$i}.id", __('import.id_taken'));
                }
            }
        }

        // An item id that exists but is not the user's also makes its children unusable.
        /** @var list<array<string, mixed>> $children */
        $children = [...$this->input('exceptions', []), ...$this->input('checkins', [])];
        $foreignItems = ScheduleItem::whereIn('id', collect($children)->pluck('schedule_item_id')->unique())
            ->where('user_id', '!=', $userId)->pluck('id')->flip();
        foreach (['exceptions', 'checkins'] as $key) {
            foreach ($this->input($key, []) as $i => $row) {
                if ($foreignItems->has($row['schedule_item_id'])) {
                    $validator->errors()->add("{$key}.{$i}.schedule_item_id", __('import.unknown_item'));
                }
            }
        }
    }

    /** Rows the user already has (same id) are not new, so they do not count toward the limit. */
    private function checkLimits(Validator $validator): void
    {
        $user = $this->user();

        $newTodos = count($this->input('todos', [])) - $user->todos()->whereIn('id', array_column($this->input('todos', []), 'id'))->count();
        if ($user->todos()->count() + $newTodos > Todo::MAX_PER_USER) {
            $validator->errors()->add('todos', __('validation.todo_limit', ['max' => Todo::MAX_PER_USER]));
        }

        $newItems = count($this->input('schedule_items', [])) - $user->scheduleItems()->whereIn('id', array_column($this->input('schedule_items', []), 'id'))->count();
        if ($user->scheduleItems()->count() + $newItems > ScheduleItem::MAX_PER_USER) {
            $validator->errors()->add('schedule_items', __('schedule.limit', ['max' => ScheduleItem::MAX_PER_USER]));
        }
    }
}
