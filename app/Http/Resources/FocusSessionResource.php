<?php

namespace App\Http\Resources;

use App\Models\FocusSession;
use App\Support\FocusSessionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FocusSession */
class FocusSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'end_reason' => $this->end_reason,
            'started_at' => FocusSessionPayload::isoMs($this->started_at->getTimestampMs()),
            'ended_at' => FocusSessionPayload::isoMs($this->ended_at->getTimestampMs()),
            'local_date' => $this->local_date,
            'planned_focus_ms' => $this->planned_focus_ms,
            'focus_ms' => $this->focus_ms,
            'break_ms' => $this->break_ms,
            'paused_ms' => $this->paused_ms,
            'todo_id' => $this->todo_id,
            'schedule_item_id' => $this->schedule_item_id,
            'label' => $this->label,
            'segments' => $this->segments,
            'laps' => $this->laps,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
