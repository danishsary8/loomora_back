<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Public and cheap by default. With ?deep=1 it also checks the database.
     * Errors are never shown to the caller; they are only reported as "down".
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (! $request->boolean('deep')) {
            return response()->json(['status' => 'ok']);
        }

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'database' => 'down'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
