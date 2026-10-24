<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PasswordResetController extends Controller
{
    private const GENERIC = 'If an account exists for that email, a password reset link has been sent.';

    public function __construct(private readonly PasswordResetService $passwords) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendLink($request->string('email')->value());

        return ApiResponse::success(['message' => self::GENERIC]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->passwords->reset($request->validated())) {
            return ApiResponse::error('invalid_reset_token', 'This password reset link is invalid or has expired.', 422);
        }

        return ApiResponse::success(['message' => 'Password reset successfully. Please sign in again.']);
    }
}
