<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreFeedbackRequest;
use App\Mail\FeedbackReceivedMail;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends Controller
{
    /** Open to guests too; a token, if sent, links the message to the account. */
    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $feedback = new Feedback($request->validated());
        $feedback->user()->associate(Auth::guard('sanctum')->user());
        $feedback->save();

        // The message is safe in the database even if the email fails.
        if ($to = config('services.feedback.to')) {
            try {
                Mail::to($to)->send(new FeedbackReceivedMail($feedback));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => __('account.feedback_thanks')], 201);
    }
}
