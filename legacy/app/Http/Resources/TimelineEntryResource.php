<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TimelineEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'start' => $this->startHm(), 'end' => $this->endHm(), 'description' => $this->description, 'category' => $this->category->value, 'sort_order' => $this->sort_order];
    }
}
