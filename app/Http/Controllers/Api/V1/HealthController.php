<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Cheap by default (Render pings it); `?deep=1` also checks the database. */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->boolean('deep')) {
            return response()->json(['status' => 'ok']);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'database' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
