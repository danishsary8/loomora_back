<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\UpstreamServiceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PrayerTime\ShowPrayerTimeRequest;
use App\Http\Resources\PrayerTimeResource;
use App\Models\User;
use App\Services\PrayerTimeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

final class PrayerTimeController extends Controller
{
    public function __construct(private readonly PrayerTimeService $prayerTimeService) {}

    /**
     * GET /api/v1/prayer-times/{date}?lat=&lng=
     */
    public function show(ShowPrayerTimeRequest $request, string $date): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $payload = $this->prayerTimeService->forDate(
                $user,
                Carbon::createFromFormat('Y-m-d', $request->validated('date'))->startOfDay(),
                $request->latitude(),
                $request->longitude(),
            );
        } catch (UpstreamServiceException $exception) {
            // Prayer badges are an optional enhancement. The manual routine
            // remains usable when the provider is offline, so report that
            // state without turning a healthy dashboard request into an HTTP
            // error (which also avoids noisy browser-console failures).
            return ApiResponse::success([
                'date' => $request->validated('date'),
                'latitude' => $request->latitude(),
                'longitude' => $request->longitude(),
                'timings' => null,
            ], [
                'source' => 'unavailable',
                'warning' => $exception->getMessage(),
            ]);
        }

        return ApiResponse::success(
            new PrayerTimeResource($payload),
            ['source' => $payload['source']],
        );
    }
}
