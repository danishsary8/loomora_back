<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TimelineEntry\StoreTimelineEntryRequest;
use App\Http\Requests\TimelineEntry\UpdateTimelineEntryRequest;
use App\Http\Resources\TimelineEntryResource;
use App\Models\User;
use App\Services\ScheduleMutationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimelineEntryController extends Controller
{
    public function __construct(private readonly ScheduleMutationService $schedules) {}

    public function index(Request $request, int $dayGroup): JsonResponse
    {
        return ApiResponse::success(TimelineEntryResource::collection($this->schedules->entries($this->user($request), $dayGroup)));
    }

    public function store(StoreTimelineEntryRequest $request, int $dayGroup): JsonResponse
    {
        return ApiResponse::created(new TimelineEntryResource($this->schedules->createEntry($this->user($request), $dayGroup, $request->validated())));
    }

    public function update(UpdateTimelineEntryRequest $request, int $timelineEntry): JsonResponse
    {
        return ApiResponse::success(new TimelineEntryResource($this->schedules->updateEntry($this->user($request), $timelineEntry, $request->validated())));
    }

    public function destroy(Request $request, int $timelineEntry): JsonResponse
    {
        $this->schedules->deleteEntry($this->user($request), $timelineEntry);

        return ApiResponse::success(['deleted' => true, 'id' => $timelineEntry]);
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
