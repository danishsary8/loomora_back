<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportRequest;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    /** Same answer on a repeat: the counts show what was new and what was already there. */
    public function store(ImportRequest $request, ImportService $import): JsonResponse
    {
        return response()->json(['data' => $import->import($request->user(), $request->validated())]);
    }
}
