<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Signed, expiring email verification links. The signature covers the
 * user's current email, so changing the email invalidates old links.
 */
class EmailVerification
{
    /**
     * @return array{id: string, expires: int, signature: string}
     */
    public function payloadFor(User $user): array
    {
        $expires = Carbon::now()->addMinutes(config('app.verify_email_expire'))->getTimestamp();

        return [
            'id' => $user->id,
            'expires' => $expires,
            'signature' => $this->sign($user, $expires),
        ];
    }

    public function urlFor(User $user): string
    {
        return rtrim(config('app.frontend_url'), '/').'/verify-email?'.http_build_query($this->payloadFor($user));
    }

    public function isValid(User $user, int $expires, string $signature): bool
    {
        return $expires >= Carbon::now()->getTimestamp()
            && hash_equals($this->sign($user, $expires), $signature);
    }

    private function sign(User $user, int $expires): string
    {
        return hash_hmac('sha256', "{$user->id}|{$user->email}|{$expires}", config('app.key'));
    }
}
