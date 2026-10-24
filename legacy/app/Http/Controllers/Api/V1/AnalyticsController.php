<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\TrackEventRequest;
use App\Http\Requests\Analytics\TrackPageViewRequest;
use App\Services\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function pageView(TrackPageViewRequest $request): JsonResponse
    {
        $this->analytics->pageView($request->string('path')->value(), $request->validated('idempotency_key'));

        return ApiResponse::success(['accepted' => true]);
    }

    public function event(TrackEventRequest $request): JsonResponse
    {
        $this->analytics->clientEvent($request->string('event')->value(), $request->string('idempotency_key')->value());

        return ApiResponse::success(['accepted' => true]);
    }
}
