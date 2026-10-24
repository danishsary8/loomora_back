<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationSetting\UpdateNotificationSettingRequest;
use App\Http\Resources\NotificationSettingResource;
use App\Models\User;
use App\Services\NotificationSettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationSettingController extends Controller
{
    public function __construct(private readonly NotificationSettingService $service) {}

    /**
     * GET /api/v1/notification-settings
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            new NotificationSettingResource($this->service->forUser($user)),
        );
    }

    /**
     * PUT /api/v1/notification-settings
     */
    public function update(UpdateNotificationSettingRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{enabled?: bool, minutes_before?: int} $data */
        $data = $request->validated();

        return ApiResponse::success(
            new NotificationSettingResource($this->service->update($user, $data)),
        );
    }
}
