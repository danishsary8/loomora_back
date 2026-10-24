<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ValidatesPathDate;
use App\Http\Controllers\Controller;
use App\Models\ScheduleException;
use App\Models\ScheduleItem;
use App\Services\ScheduleService;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ScheduleCheckinController extends Controller
{
    use ValidatesPathDate;

    /** Marks one occurrence done (docs/08 rule 7). Idempotent. */
    public function store(ScheduleItem $item, string $date, ScheduleService $schedule, StreakService $streak): JsonResponse
    {
        Gate::authorize('manage', $item);
        $this->assertValidDate($date);

        $skipped = $item->exceptions()->where('date', $date)->where('type', ScheduleException::SKIP)->exists();
        if ($skipped || ! $schedule->matchesRule($item, $date)) {
            throw ValidationException::withMessages(['date' => __('schedule.no_occurrence')]);
        }

        $checkin = $item->checkins()->firstOrCreate(['date' => $date], ['done_at' => now()]);
        $streak->recompute($item->user);

        return response()->json(['data' => [
            'item_id' => $item->id,
            'date' => $date,
            'done' => true,
            'done_at' => $checkin->done_at->toIso8601ZuluString(),
        ]], $checkin->wasRecentlyCreated ? 201 : 200);
    }

    /** Undo. Safe to repeat. */
    public function destroy(ScheduleItem $item, string $date, StreakService $streak): Response
    {
        Gate::authorize('manage', $item);
        $this->assertValidDate($date);

        $item->checkins()->where('date', $date)->delete();
        $streak->recompute($item->user);

        return response()->noContent();
    }
}
