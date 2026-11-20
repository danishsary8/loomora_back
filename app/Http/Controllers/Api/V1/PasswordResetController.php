<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PasswordResetController extends Controller
{
    /** Same answer whether or not the email exists, so accounts can't be discovered. */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $connection = (new User)->getConnection();
        $broker = $this->databaseBroker($connection);
        $credentials = $request->validated();
        $mail = $connection->transaction(function () use ($broker, $credentials) {
            $userId = User::where('email', $credentials['email'])->lockForUpdate()->value('id');
            $mail = null;
            // Keep the broker's throttle and timing floor for missing or throttled accounts.
            $broker->sendResetLink([...$credentials, 'id' => $userId], function (User $user, string $token) use (&$mail) {
                $url = config('app.frontend_url').'/reset-password?'.http_build_query([
                    'token' => $token,
                    'email' => $user->email,
                ]);

                $mail = (new ResetPasswordMail($user, $url))->to($user);
            });

            return $mail;
        });

        // Delivery can fail after the transport accepts the message. Keep the committed
        // token and let the existing broker throttle govern retries.
        if ($mail !== null) {
            try {
                Mail::send($mail);
            } catch (Throwable) {
                Log::warning('Password reset email delivery failed.');
            }
        }

        return response()->json(['message' => __('auth_flow.reset_link_sent')]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $connection = (new User)->getConnection();
        $broker = $this->databaseBroker($connection);

        $credentials = $request->validated();
        $status = $connection->transaction(function () use ($broker, $credentials) {
            // Serialize credential changes before the broker reads the reset token.
            $userId = User::where('email', $credentials['email'])->lockForUpdate()->value('id');

            // The broker consumes the reset token after the callback returns.
            return $broker->reset([...$credentials, 'id' => $userId], function (User $user, string $password) {
                if (! $user->forceFill([
                    'password' => $password,
                    'google_exchange_digest' => null,
                    'google_exchange_expires_at' => null,
                ])->save()) {
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

    private function databaseBroker(Connection $connection): PasswordBroker
    {
        $broker = Password::broker();
        $repository = $broker instanceof PasswordBroker ? $broker->getRepository() : null;

        if (! $broker instanceof PasswordBroker || ! $repository instanceof DatabaseTokenRepository
            || $repository->getConnection() !== $connection) {
            throw new RuntimeException('Password resets require a token repository on the user database connection.');
        }

        return $broker;
    }
}
