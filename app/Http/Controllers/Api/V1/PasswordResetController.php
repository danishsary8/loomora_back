<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PasswordResetController extends Controller
{
    /** Same answer whether or not the email exists, so accounts can't be discovered. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated(), function (User $user, string $token) {
            $url = config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->email,
            ]);

            Mail::to($user)->send(new ResetPasswordMail($user, $url));
        });

        return response()->json(['message' => __('auth_flow.reset_link_sent')]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $connection = (new User)->getConnection();
        $broker = Password::broker();
        $repository = $broker instanceof PasswordBroker ? $broker->getRepository() : null;

        if (! $repository instanceof DatabaseTokenRepository || $repository->getConnection() !== $connection) {
            throw new RuntimeException('Password resets require a token repository on the user database connection.');
        }

        $credentials = $request->validated();
        $status = $connection->transaction(function () use ($broker, $credentials) {
            // Serialize credential changes before the broker reads the reset token.
            $userId = User::where('email', $credentials['email'])->lockForUpdate()->value('id');

            // The broker consumes the reset token after the callback returns.
            return $broker->reset([...$credentials, 'id' => $userId], function (User $user, string $password) {
                if (! $user->forceFill(['password' => $password])->save()) {
                    throw new RuntimeException('Password reset was cancelled.');
                }

                $user->tokens()->delete();
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__('auth_flow.reset_invalid')]]);
        }

        return response()->json(['message' => __('auth_flow.password_reset')]);
    }
}
