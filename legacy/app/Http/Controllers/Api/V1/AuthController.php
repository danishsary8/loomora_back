<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller: validation lives in Form Requests, logic in AuthService,
 * shaping in Resources, envelope in ApiResponse.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            $request->safe()->only(['name', 'email', 'password']),
            $request->string('device_name', 'api')->value(),
        );

        return ApiResponse::created(
            new UserResource($result['user']),
            ['token' => $result['token'], 'token_type' => 'Bearer'],
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->string('email')->value(),
            $request->string('password')->value(),
            $request->string('device_name', 'api')->value(),
        );

        return ApiResponse::success(
            new UserResource($result['user']),
            ['token' => $result['token'], 'token_type' => 'Bearer'],
        );
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authService->logout($user);

        return ApiResponse::success(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(new UserResource($user));
    }
}
