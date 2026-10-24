<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ChecklistDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return [
            'date' => $payload['date'],
            'items' => $payload['items'],
            'checked_ids' => $payload['checked_ids'],
            'completed' => $payload['completed'],
            'total' => $payload['total'],
        ];
    }
}
