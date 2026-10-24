<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;

class TodoPolicy
{
    /** Every action on a to-do is owner-only. */
    public function manage(User $user, Todo $todo): bool
    {
        return $todo->user_id === $user->id;
    }
}
