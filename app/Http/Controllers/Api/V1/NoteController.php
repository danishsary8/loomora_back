<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\NoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Note;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class NoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return NoteResource::collection(
            $request->user()->notes()->latest('updated_at')->orderByDesc('id')->simplePaginate(50),
        );
    }

    public function store(NoteRequest $request, NoteService $notes): JsonResponse
    {
        return (new NoteResource($notes->create($request->user(), $request->validated())))
            ->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $note): NoteResource
    {
        return new NoteResource($request->user()->notes()->findOrFail($note));
    }

    public function update(NoteRequest $request, string $note, NoteService $notes): NoteResource
    {
        return new NoteResource($notes->update($request->user(), $note, $request->validated()));
    }

    public function destroy(Request $request, string $note): Response
    {
        DB::transaction(function () use ($request, $note) {
            $stored = $request->user()->notes()->withTrashed()->lockForUpdate()->findOrFail($note);
            if (! $stored->trashed()) {
                $stored->delete();
            }
        });

        return response()->noContent();
    }

    public function restore(Request $request, string $note): NoteResource
    {
        $stored = DB::transaction(function () use ($request, $note) {
            $stored = $request->user()->notes()->withTrashed()->lockForUpdate()->findOrFail($note);
            if ($stored->deleted_at !== null) {
                abort_unless($stored->deleted_at->gt(now()->subDays(Note::RESTORE_WITHIN_DAYS)),
                    Response::HTTP_GONE, __('notes.restore_expired'));
                $stored->restore();
            }

            return $stored;
        });

        return new NoteResource($stored);
    }
}
