<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DayGroup\DeleteDayGroupRequest;
use App\Http\Requests\DayGroup\StoreDayGroupRequest;
use App\Http\Requests\DayGroup\UpdateDayGroupRequest;
use App\Http\Resources\DayGroupResource;
use App\Models\User;
use App\Services\ScheduleMutationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DayGroupController extends Controller
{
    public function __construct(private readonly ScheduleMutationService $schedules) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(DayGroupResource::collection($this->schedules->groups($this->user($request))));
    }

    public function store(StoreDayGroupRequest $request): JsonResponse
    {
        return ApiResponse::created(new DayGroupResource($this->schedules->createGroup($this->user($request), $request->validated())));
    }

    public function update(UpdateDayGroupRequest $request, int $dayGroup): JsonResponse
    {
        return ApiResponse::success(new DayGroupResource($this->schedules->updateGroup($this->user($request), $dayGroup, $request->validated())));
    }

    public function destroy(DeleteDayGroupRequest $request, int $dayGroup): JsonResponse
    {
        $this->schedules->deleteGroup($this->user($request), $dayGroup);

        return ApiResponse::success(['deleted' => true, 'id' => $dayGroup]);
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
