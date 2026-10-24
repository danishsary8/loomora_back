<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\CopyDayRequest;
use App\Http\Requests\Schedule\ScheduleDayRequest;
use App\Http\Requests\Schedule\ScheduleNowRequest;
use App\Http\Requests\Schedule\ScheduleWeekRequest;
use App\Http\Resources\ScheduleItemResource;
use App\Services\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $schedule) {}

    public function day(ScheduleDayRequest $request): JsonResponse
    {
        $date = $request->validated('date');

        return response()->json([
            'date' => $date,
            'data' => $this->schedule->forDate($request->user(), $date),
        ]);
    }

    /** Seven days starting at `start`. */
    public function week(ScheduleWeekRequest $request): JsonResponse
    {
        $start = CarbonImmutable::parse($request->validated('start'));
        $days = $this->schedule->forRange($request->user(), $start->toDateString(), $start->addDays(6)->toDateString());

        return response()->json([
            'start' => $start->toDateString(),
            'data' => collect($days)->map(fn ($occurrences, $date) => ['date' => $date, 'occurrences' => $occurrences])->values(),
        ]);
    }

    public function now(ScheduleNowRequest $request): JsonResponse
    {
        return response()->json($this->schedule->nowAndNext(
            $request->user(),
            $request->validated('date'),
            $request->validated('time'),
        ));
    }

    /**
     * Makes every item that runs on `from_weekday` also run on `to_weekdays`
     * (edits the repeat definitions, i.e. "all repeats").
     */
    public function copyDay(CopyDayRequest $request): AnonymousResourceCollection
    {
        $from = $request->validated('from_weekday');
        $to = $request->validated('to_weekdays');

        $items = $request->user()->scheduleItems()->get()
            ->filter(fn ($item) => in_array($from, $item->days, true));

        foreach ($items as $item) {
            $days = array_values(array_unique([...$item->days, ...$to]));
            sort($days);
            $item->update(['days' => $days]);
        }

        return ScheduleItemResource::collection($items->values());
    }
}
