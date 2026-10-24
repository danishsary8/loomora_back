<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final class PasswordResetService
{
    public function sendLink(string $email): void
    {
        Password::sendResetLink(['email' => strtolower($email)]);
    }

    public function reset(array $credentials): bool
    {
        return Password::reset($credentials, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        }) === Password::PASSWORD_RESET;
    }
}
