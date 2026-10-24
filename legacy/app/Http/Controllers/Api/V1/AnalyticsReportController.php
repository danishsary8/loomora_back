<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AnalyticsReportController extends Controller
{
    public function __construct(private readonly AnalyticsReportService $reports) {}

    public function __invoke(): JsonResponse
    {
        return ApiResponse::success($this->reports->summary());
    }
}
