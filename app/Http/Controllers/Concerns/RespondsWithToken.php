<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

trait RespondsWithToken
{
    /** Issue a 30-day API token: `{token, expires_at, user}` (docs/openapi.yaml TokenResponse). */
    protected function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601ZuluString(),
            'user' => new UserResource($user),
        ], $status);
    }
}
