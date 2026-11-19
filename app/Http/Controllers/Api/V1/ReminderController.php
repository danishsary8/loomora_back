<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminder\SnoozeReminderRequest;
use App\Http\Resources\TodoResource;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** In-app reminders (task B-021). Push notifications come later (docs/02). */
class ReminderController extends Controller
{
    public function due(Request $request): AnonymousResourceCollection
    {
        $todos = $request->user()->todos()->dueReminders()->with('subtasks')->get();

        return TodoResource::collection($todos);
    }

    public function dismiss(Todo $todo): TodoResource
    {
        Gate::authorize('remind', $todo);

        $todo->dismissReminder();

        return new TodoResource($todo);
    }

    public function snooze(SnoozeReminderRequest $request, Todo $todo): TodoResource
    {
        $todo->snoozeReminder($request->integer('minutes'));

        return new TodoResource($todo);
    }
}
