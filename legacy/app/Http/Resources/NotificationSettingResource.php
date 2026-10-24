<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationSetting
 */
final class NotificationSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'minutes_before' => (int) $this->minutes_before,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
