<?php

namespace App\Http\Resources;

use App\Models\Todo;
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
        $today = static::$today ?? now($request->user()->timezone);

        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'due_date' => $this->due_date?->toDateString(),
            'done_at' => $this->done_at?->toIso8601ZuluString(),
            'overdue' => $this->isOverdueOn($today),
            'old' => $this->isOldOn($today),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
