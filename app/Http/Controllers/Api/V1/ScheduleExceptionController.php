<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ValidatesPathDate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\PutScheduleExceptionRequest;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScheduleExceptionController extends Controller
{
    use ValidatesPathDate;

    /** "Only that day": skip or override (docs/08 rule 5). Idempotent; replaces an earlier exception. */
    public function put(PutScheduleExceptionRequest $request, ScheduleItem $item, string $date, ScheduleService $schedule): JsonResponse
    {
        Gate::authorize('manage', $item);
        $this->assertValidDate($date);

        if (! $schedule->matchesRule($item, $date)) {
            throw ValidationException::withMessages(['date' => __('schedule.no_occurrence')]);
        }

        $isOverride = $request->validated('type') === ScheduleException::OVERRIDE;

        $exception = $item->exceptions()->updateOrCreate(['date' => $date], [
            'type' => $request->validated('type'),
            'title' => $isOverride ? $request->validated('title') : null,
            'start_time' => $isOverride ? $request->validated('start_time') : null,
            'end_time' => $isOverride ? $request->validated('end_time') : null,
        ]);

        return response()->json(['data' => [
            'item_id' => $item->id,
            'date' => $date,
            'type' => $exception->type,
            'title' => $exception->title,
            'start_time' => ScheduleItem::hhmm($exception->start_time),
            'end_time' => ScheduleItem::hhmm($exception->end_time),
        ]]);
    }

    /** Restores that day. Safe to repeat. */
    public function destroy(ScheduleItem $item, string $date): Response
    {
        Gate::authorize('manage', $item);
        $this->assertValidDate($date);

        $item->exceptions()->where('date', $date)->delete();

        return response()->noContent();
    }
}
