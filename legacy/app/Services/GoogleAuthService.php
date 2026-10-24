<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

final class GoogleAuthService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function authorizationUrl(): string
    {
        $state = Str::random(64);
        Cache::put('oauth:google:state:'.hash('sha256', $state), true, now()->addMinutes(10));

        return Socialite::driver('google')->stateless()->with(['state' => $state, 'prompt' => 'select_account'])->redirect()->getTargetUrl();
    }

    public function callback(string $state): string
    {
        if ($state === '' || ! Cache::pull('oauth:google:state:'.hash('sha256', $state))) {
            throw new RuntimeException('oauth_state_invalid|Google sign-in expired or could not be verified. Please try again.');
        }
        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable) {
            throw new RuntimeException('oauth_failed|Google sign-in could not be completed. Please try again.');
        }
        $email = strtolower((string) $google->getEmail());
        if ($email === '' || ! $google->getId()) {
            throw new RuntimeException('oauth_email_missing|Google did not provide a usable verified email address.');
        }
        $result = DB::transaction(function () use ($google, $email): array {
            $user = User::withTrashed()->where('email', $email)->first();
            if ($user?->trashed()) {
                abort(403, 'This account is unavailable.');
            }
            $created = ! $user;
            if ($created) {
                $user = User::forceCreate(['name' => $google->getName() ?: Str::before($email, '@'), 'email' => $email, 'password' => null, 'google_id' => (string) $google->getId(), 'email_verified_at' => now(), 'terms_accepted_at' => now()]);
                NotificationSetting::create(['user_id' => $user->id]);
            } else {
                $user->forceFill(['google_id' => (string) $google->getId(), 'email_verified_at' => $user->email_verified_at ?? now(), 'terms_accepted_at' => $user->terms_accepted_at ?? now()])->save();
                $user->notificationSetting()->firstOrCreate([]);
            }

            return ['user' => $user, 'created' => $created];
        });
        /** @var User $user */
        $user = $result['user'];
        if ($result['created']) {
            $this->analytics->signupCompleted('google', (int) $user->id);
        }
        $code = Str::random(80);
        Cache::put('oauth:google:handoff:'.hash('sha256', $code), ['user_id' => $user->id, 'token' => $user->createToken('google-web')->plainTextToken], now()->addMinutes(2));

        return $code;
    }

    public function exchange(string $code): ?array
    {
        $handoff = Cache::pull('oauth:google:handoff:'.hash('sha256', $code));
        if (! is_array($handoff)) {
            return null;
        }

        return ['user' => User::findOrFail($handoff['user_id']), 'token' => $handoff['token']];
    }
}
