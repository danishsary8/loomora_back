<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DayGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'weekdays' => $this->weekdayValues(),
            'timeline' => TimelineEntryResource::collection($this->whenLoaded('timelineEntries'))->resolve($request),
            'checklist' => ChecklistItemResource::collection($this->whenLoaded('checklistItems'))->resolve($request),
        ];
    }
}
