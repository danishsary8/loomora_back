<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Focus\FocusSessionDayRequest;
use App\Http\Requests\Focus\StoreFocusSessionRequest;
use App\Http\Resources\FocusSessionResource;
use App\Services\FocusSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FocusSessionController extends Controller
{
    /** The user's sessions that started on one local day, oldest first. */
    public function index(FocusSessionDayRequest $request): AnonymousResourceCollection
    {
        $sessions = $request->user()->focusSessions()
            ->where('local_date', $request->validated('date'))
            ->orderBy('started_at')
            ->get();

        return FocusSessionResource::collection($sessions);
    }

    /** 201 for a new session, 200 for an identical retry. */
    public function store(StoreFocusSessionRequest $request, FocusSessionService $sessions): JsonResponse
    {
        [$session, $created] = $sessions->record($request->user(), $request->payload());

        return (new FocusSessionResource($session))->response()->setStatusCode($created ? 201 : 200);
    }

    /** Another user's session is a 404, like an unknown one. */
    public function show(Request $request, string $focusSession): FocusSessionResource
    {
        return new FocusSessionResource($request->user()->focusSessions()->findOrFail($focusSession));
    }
}
