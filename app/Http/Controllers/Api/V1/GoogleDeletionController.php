<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GoogleDeletionProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GoogleDeletionController extends Controller
{
    public function start(Request $request, GoogleDeletionProof $proof): JsonResponse
    {
        $input = Validator::make($request->json()->all(), [
            'browser_challenge' => ['required', 'string', GoogleDeletionProof::BROWSER_SECRET],
        ])->validate();

        return response()->json(['data' => $proof->start($request->user(), $input['browser_challenge'])], 201);
    }

    public function verify(Request $request, GoogleDeletionProof $proof): JsonResponse
    {
        $input = Validator::make($request->json()->all(), [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:2048'],
            'state' => ['required', 'string', GoogleDeletionProof::BROWSER_SECRET],
            'browser_verifier' => ['required', 'string', GoogleDeletionProof::BROWSER_SECRET],
        ])->validate();

        return response()->json(['data' => $proof->verify(
            $request->user(),
            $input['challenge_id'],
            $input['code'],
            $input['state'],
            $input['browser_verifier'],
        )]);
    }
}
