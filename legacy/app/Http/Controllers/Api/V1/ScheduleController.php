<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ShowTodayRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    /**
     * GET /api/v1/schedule/today[?date=YYYY-MM-DD]
     */
    public function today(ShowTodayRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $this->scheduleService->today($user, $request->resolveDate());

        return ApiResponse::success(
            new ScheduleResource($payload),
            [
                'resolved_server_side' => ! $request->usedClientDate(),
                'timezone' => config('app.timezone'),
            ],
        );
    }

    /**
     * GET /api/v1/schedule/day-groups/{dayGroup}
     */
    public function show(ShowTodayRequest $request, int $dayGroup): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $this->scheduleService->group($user, $dayGroup);

        return ApiResponse::success(new ScheduleResource($payload));
    }
}
