<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;

final class NotificationSettingService
{
    /**
     * Fetch settings, creating defaults on first access.
     */
    public function forUser(User $user): NotificationSetting
    {
        return NotificationSetting::firstOrCreate(
            ['user_id' => $user->id],
            ['enabled' => true, 'minutes_before' => 5],
        );
    }

    /**
     * @param  array{enabled?: bool, minutes_before?: int}  $data
     */
    public function update(User $user, array $data): NotificationSetting
    {
        $settings = $this->forUser($user);
        $settings->fill($data)->save();

        return $settings->refresh();
    }
}
