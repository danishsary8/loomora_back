<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PrayerTimeResource extends JsonResource
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
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'timings' => $payload['timings'],
        ];
    }
}
