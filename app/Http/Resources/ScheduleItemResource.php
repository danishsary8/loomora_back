<?php

namespace App\Http\Resources;

use App\Models\ScheduleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScheduleItem */
class ScheduleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $days = $this->days;
        sort($days);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'start_time' => ScheduleItem::hhmm($this->start_time),
            'end_time' => ScheduleItem::hhmm($this->end_time),
            'days' => $days,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
