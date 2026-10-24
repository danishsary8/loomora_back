<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class EmailVerificationService
{
    public function verify(int $id, string $hash): User
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403, 'This verification link is invalid.');
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $user;
    }

    public function resend(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }
        $user->sendEmailVerificationNotification();

        return true;
    }
}
