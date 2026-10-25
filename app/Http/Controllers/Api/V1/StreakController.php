<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Todo\TodayRequest;
use App\Services\StreakService;
use Illuminate\Http\JsonResponse;

class StreakController extends Controller
{
    /** Lazy recompute on app open (docs/08 rule 8). */
    public function show(TodayRequest $request, StreakService $streak): JsonResponse
    {
        return response()->json(['data' => $streak->recompute($request->user(), $request->localDate()->toDateString())]);
    }
}
