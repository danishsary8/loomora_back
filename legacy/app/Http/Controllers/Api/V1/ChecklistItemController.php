<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChecklistItem\StoreChecklistItemRequest;
use App\Http\Requests\ChecklistItem\UpdateChecklistItemRequest;
use App\Http\Resources\ChecklistItemResource;
use App\Models\User;
use App\Services\ScheduleMutationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChecklistItemController extends Controller
{
    public function __construct(private readonly ScheduleMutationService $schedules) {}

    public function index(Request $request, int $dayGroup): JsonResponse
    {
        return ApiResponse::success(ChecklistItemResource::collection($this->schedules->items($this->user($request), $dayGroup)));
    }

    public function store(StoreChecklistItemRequest $request, int $dayGroup): JsonResponse
    {
        return ApiResponse::created(new ChecklistItemResource($this->schedules->createItem($this->user($request), $dayGroup, $request->validated())));
    }

    public function update(UpdateChecklistItemRequest $request, int $checklistItem): JsonResponse
    {
        return ApiResponse::success(new ChecklistItemResource($this->schedules->updateItem($this->user($request), $checklistItem, $request->validated())));
    }

    public function destroy(Request $request, int $checklistItem): JsonResponse
    {
        $this->schedules->deleteItem($this->user($request), $checklistItem);

        return ApiResponse::success(['deleted' => true, 'id' => $checklistItem]);
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
