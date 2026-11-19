<?php

namespace App\Http\Resources;

use App\Models\Todo;
use App\Support\LocalDate;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Todo */
class TodoResource extends JsonResource
{
    /** The user's local "today", used for the computed flags. */
    public static ?CarbonInterface $today = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = static::$today ?? LocalDate::startOfDay($request->user(), LocalDate::todayFor($request->user()));

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'priority' => $this->priority,
            'notes' => $this->notes,
            'parent_id' => $this->parent_id,
            'repeat_rule' => $this->repeat_rule,
            'next_todo_id' => $this->next_todo_id,
            'remind_at' => $this->remind_at?->toIso8601ZuluString(),
            'due_date' => $this->due_date?->toDateString(),
            'done_at' => $this->done_at?->toIso8601ZuluString(),
            'overdue' => $this->isOverdueOn($today),
            'old' => $this->isOldOn($today),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'subtasks' => $this->isSubtask() ? [] : TodoResource::collection($this->subtasks),
        ];
    }
}
