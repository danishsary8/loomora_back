<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Owns all authentication business logic. Controllers only orchestrate.
 */
final class AuthService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Register a user and issue their first token.
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data, string $deviceName = 'api'): array
    {
        /** @var User $user */
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                // 'hashed' cast on the model handles hashing.
                'password' => $data['password'],
                'terms_accepted_at' => now(),
            ]);

            // Every user gets sane default reminder settings up front.
            NotificationSetting::create(['user_id' => $user->id]);

            return $user;
        });

        $user->sendEmailVerificationNotification();
        $this->analytics->signupCompleted('email', (int) $user->id);

        return [
            'user' => $user,
            'token' => $this->issueToken($user, $deviceName),
        ];
    }

    /**
     * Verify credentials and issue a token.
     *
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, string $deviceName = 'api'): array
    {
        $user = User::where('email', strtolower($email))->first();

        // Constant-ish behaviour: same error whether the email or password is wrong.
        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            throw new InvalidCredentialsException;
        }

        $user->notificationSetting()->firstOrCreate([]);

        return [
            'user' => $user,
            'token' => $this->issueToken($user, $deviceName),
        ];
    }

    /**
     * Revoke only the token used for the current request.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
    }

    private function issueToken(User $user, string $deviceName): string
    {
        return $user->createToken($deviceName)->plainTextToken;
    }
}
