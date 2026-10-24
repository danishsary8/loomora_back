<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'goal' => $this->goal,
            'heard_from' => $this->heard_from,
            'survey_done' => $this->survey_done,
            'current_streak' => $this->current_streak,
            'longest_streak' => $this->longest_streak,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
