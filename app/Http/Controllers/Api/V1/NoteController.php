<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class NoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return NoteResource::collection(
            $request->user()->notes()->latest('updated_at')->orderByDesc('id')->simplePaginate(50),
        );
    }

    /** 201 for a new note, 200 for an identical replay of an active one. */
    public function store(NoteRequest $request, NoteService $notes): JsonResponse
    {
        [$note, $created] = $notes->create($request->user(), $request->validated());

        return (new NoteResource($note))->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Request $request, string $note): NoteResource
    {
        return new NoteResource($request->user()->notes()->findOrFail($note));
    }

    public function update(NoteRequest $request, string $note, NoteService $notes): NoteResource
    {
        return new NoteResource($notes->update(
            $request->user(), $note, $request->noteFields(), NoteRequest::expectedRevision($request),
        ));
    }

    public function destroy(Request $request, string $note, NoteService $notes): Response
    {
        $request->validate(NoteRequest::expectedRevisionRules());
        $notes->delete($request->user(), $note, NoteRequest::expectedRevision($request));

        return response()->noContent();
    }

    public function restore(Request $request, string $note, NoteService $notes): NoteResource
    {
        return new NoteResource($notes->restore($request->user(), $note));
    }
}
