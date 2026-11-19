<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Day\IntentionDateRequest;
use App\Http\Requests\Day\SaveIntentionRequest;
use Illuminate\Http\JsonResponse;

class DailyIntentionController extends Controller
{
    public function show(IntentionDateRequest $request): JsonResponse
    {
        $intention = $request->user()->dailyIntentions()->where('local_date', $request->localDate()->toDateString())->first();

        return $this->respond($request->localDate()->toDateString(), $intention?->text);
    }

    /** Creates or updates the day's intention; an empty text deletes it. */
    public function update(SaveIntentionRequest $request): JsonResponse
    {
        $date = $request->localDate()->toDateString();
        $text = $request->text();
        $intentions = $request->user()->dailyIntentions();

        if ($text === null) {
            $intentions->where('local_date', $date)->delete();
        } else {
            $intentions->updateOrCreate(['local_date' => $date], ['text' => $text]);
        }

        return $this->respond($date, $text);
    }

    private function respond(string $date, ?string $text): JsonResponse
    {
        return response()->json(['data' => ['date' => $date, 'text' => $text]]);
    }
}
