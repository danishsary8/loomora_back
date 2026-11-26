<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\StoreScheduleItemRequest;
use App\Http\Requests\Schedule\UpdateScheduleItemRequest;
use App\Http\Resources\ScheduleItemResource;
use App\Models\ScheduleItem;
use App\Services\NoteService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ScheduleItemController extends Controller
{
    /** The repeat definitions, in start-time order. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $request->user()->scheduleItems()
            ->orderBy('start_time')
            ->orderBy('created_at')
            ->get();

        return ScheduleItemResource::collection($items);
    }

    public function store(StoreScheduleItemRequest $request): ScheduleItemResource
    {
        $item = $request->user()->scheduleItems()->create([
            'category' => 'other',
            ...$request->validated(),
            'days' => array_values(array_unique($request->validated('days'))),
            'starts_on' => $request->startsOn(),
        ]);

        return new ScheduleItemResource($item->refresh());
    }

    /** "All repeats": edits the definition itself (docs/08 rule 5). */
    public function update(UpdateScheduleItemRequest $request, ScheduleItem $item): ScheduleItemResource
    {
        Gate::authorize('manage', $item);

        $item->update($request->validated());

        return new ScheduleItemResource($item->refresh());
    }

    /** "Delete all": removes the item; its exceptions and check-ins cascade (B-011), its notes become standalone (B-053). */
    public function destroy(ScheduleItem $item, NoteService $notes): Response
    {
        Gate::authorize('manage', $item);

        DB::transaction(function () use ($item, $notes) {
            $notes->detachFromBlock($item);
            $item->delete();
        });

        return response()->noContent();
    }
}
