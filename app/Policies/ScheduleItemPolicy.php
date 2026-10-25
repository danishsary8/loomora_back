<?php

namespace App\Policies;

use App\Models\ScheduleItem;
use App\Models\User;

class ScheduleItemPolicy
{
    /** Every action on a schedule item is owner-only. */
    public function manage(User $user, ScheduleItem $item): bool
    {
        return $item->user_id === $user->id;
    }
}
