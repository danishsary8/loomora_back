<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TodoResource;
use App\Models\Todo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TodoRestoreController extends Controller
{
    /**
     * Brings back a to-do deleted less than 7 days ago, with the subtasks deleted along with it.
     * Another user's to-do is a 404; a to-do that is not deleted is returned as it is.
     */
    public function __invoke(Request $request, string $todo): TodoResource
    {
        $todo = $request->user()->todos()->withTrashed()->findOrFail($todo);

        if ($todo->trashed()) {
            abort_unless($todo->canBeRestored(), Response::HTTP_GONE, __('validation.todo_restore_expired', ['days' => Todo::RESTORE_WITHIN_DAYS]));
            $this->ensureParentIsAvailable($todo);
            $todo->restoreWithSubtasks();
        }

        return new TodoResource($todo->fresh());
    }

    /** A subtask cannot come back while its parent is still deleted. */
    private function ensureParentIsAvailable(Todo $todo): void
    {
        if ($todo->isSubtask() && $todo->parent()->withTrashed()->first()?->trashed()) {
            throw ValidationException::withMessages(['parent_id' => __('validation.todo_restore_parent_deleted')]);
        }
    }
}
