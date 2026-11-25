<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StatsSummaryRequest;
use App\Services\StatsSummaryService;
use Illuminate\Http\JsonResponse;

class StatsSummaryController extends Controller
{
    public function __invoke(StatsSummaryRequest $request, StatsSummaryService $stats): JsonResponse
    {
        return response()->json([
            'data' => $stats->summarize($request->user(), $request->validated('from'), $request->validated('to')),
        ]);
    }
}
