<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Todo\StoreTodoRequest;
use App\Http\Requests\Todo\TodayRequest;
use App\Http\Requests\Todo\UpdateTodoRequest;
use App\Http\Resources\TodoResource;
use App\Models\Todo;
use App\Services\StreakService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TodoController extends Controller
{
    /** The Today list, overdue items first (docs/04 flow 1). */
    public function index(TodayRequest $request): AnonymousResourceCollection
    {
        $today = $request->localDate();
        TodoResource::$today = $today;

        $todos = $request->user()->todos()
            ->forToday($today)
            ->get()
            ->sortBy([
                fn (Todo $a, Todo $b) => $b->isOverdueOn($today) <=> $a->isOverdueOn($today),
                fn (Todo $a, Todo $b) => ($a->due_date?->timestamp ?? PHP_INT_MAX) <=> ($b->due_date?->timestamp ?? PHP_INT_MAX),
                fn (Todo $a, Todo $b) => $a->created_at <=> $b->created_at,
            ])
            ->values();

        return TodoResource::collection($todos);
    }

    public function store(StoreTodoRequest $request): TodoResource
    {
        $todo = $request->user()->todos()->create([
            'category' => 'other',
            ...$request->validated(),
        ]);

        return new TodoResource($todo->refresh());
    }

    public function update(UpdateTodoRequest $request, Todo $todo): TodoResource
    {
        Gate::authorize('manage', $todo);

        $todo->update($request->validated());

        return new TodoResource($todo);
    }

    public function destroy(Todo $todo): Response
    {
        Gate::authorize('manage', $todo);

        $todo->delete();

        return response()->noContent();
    }

    public function markDone(Todo $todo, StreakService $streak): TodoResource
    {
        Gate::authorize('manage', $todo);

        // Idempotent: a second tap keeps the original completion time.
        $todo->done_at ??= now();
        $todo->save();
        $streak->recompute($todo->user);

        return new TodoResource($todo);
    }

    public function markUndone(Todo $todo, StreakService $streak): TodoResource
    {
        Gate::authorize('manage', $todo);

        $todo->done_at = null;
        $todo->save();
        $streak->recompute($todo->user);

        return new TodoResource($todo);
    }
}
