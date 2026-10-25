<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, EmailVerification $verification): JsonResponse
    {
        $user = User::create($request->validated());

        // A mail outage must not block signup; the user can resend later.
        try {
            Mail::to($user)->send(new VerifyEmailMail($user, $verification->urlFor($user)));
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->tokenResponse($user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Google-only accounts have no password and cannot log in this way.
        if (! $user || ! $user->password || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->tokenResponse($user, 200);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function tokenResponse(User $user, int $status): JsonResponse
    {
        $token = $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601ZuluString(),
            'user' => new UserResource($user),
        ], $status);
    }
}
