<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithToken;
use App\Http\Controllers\Concerns\WithActiveAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use RespondsWithToken, WithActiveAccount;

    public function register(RegisterRequest $request, EmailVerification $verification): JsonResponse
    {
        [$user, $response] = DB::transaction(function () use ($request) {
            try {
                $user = User::create($request->validated());
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => [__('validation.unique', ['attribute' => 'email'])]]);
            }

            // A new account never inherits an email-keyed reset row left by an older version.
            $user->deletePasswordResetToken();

            return [$user, $this->tokenResponse($user, 201)];
        });

        // A mail outage must not block signup; the user can resend later.
        try {
            Mail::to($user)->send(new VerifyEmailMail($user, $verification->urlFor($user)));
        } catch (\Throwable) {
            Log::warning('Registration verification email delivery failed.');
        }

        return $response;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = User::where('email', $request->validated('email'))->lockForUpdate()->first();

            // Keep password verification and bearer issuance under the same user lock as reset.
            if (! $user || ! $user->password || ! Hash::check($request->validated('password'), $user->password)) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.failed')],
                ]);
            }

            return $this->tokenResponse($user, 200);
        });
    }

    public function logout(Request $request): Response
    {
        $this->withActiveAccount($request->user(), function (User $user) {
            $bearer = $user->currentAccessToken();
            if (data_get($bearer, 'id') === null) {
                throw new AuthenticationException;
            }

            $bearer->delete();
        });

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
