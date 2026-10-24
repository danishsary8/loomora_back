<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checklist\ShowChecklistRequest;
use App\Http\Requests\Checklist\UpdateChecklistRequest;
use App\Http\Resources\ChecklistDayResource;
use App\Models\User;
use App\Services\ChecklistService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class ChecklistController extends Controller
{
    public function __construct(private readonly ChecklistService $checklistService) {}

    /**
     * GET /api/v1/checklist/{date}
     */
    public function show(ShowChecklistRequest $request, string $date): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payload = $this->checklistService->forDate(
            $user,
            Carbon::createFromFormat('Y-m-d', $request->validated('date'))->startOfDay(),
        );

        return ApiResponse::success(new ChecklistDayResource($payload));
    }

    /**
     * PUT /api/v1/checklist/{date}
     */
    public function update(UpdateChecklistRequest $request, string $date): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<string> $checkedIds */
        $checkedIds = $request->validated('checked_ids');

        $payload = $this->checklistService->upsertForDate(
            $user,
            Carbon::createFromFormat('Y-m-d', $request->validated('date'))->startOfDay(),
            $checkedIds,
        );

        return ApiResponse::success(new ChecklistDayResource($payload));
    }
}
