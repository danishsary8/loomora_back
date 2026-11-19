<?php

namespace App\Policies;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TodoPolicy
{
    /** Every action on a to-do is owner-only. */
    public function manage(User $user, Todo $todo): bool
    {
        return $todo->user_id === $user->id;
    }

    /** Reminder actions hide another user's to-do behind a 404 (task B-021). */
    public function remind(User $user, Todo $todo): Response
    {
        return $this->manage($user, $todo) ? Response::allow() : Response::denyAsNotFound();
    }
}
