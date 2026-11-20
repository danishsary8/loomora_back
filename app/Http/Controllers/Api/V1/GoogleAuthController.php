<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithToken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleExchangeRequest;
use App\Http\Requests\Auth\GoogleRedirectRequest;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use RuntimeException;
use Throwable;

/**
 * Google sign-in for an API on another domain than the frontend (docs/09):
 * redirect -> Google -> callback -> frontend with a one-time code -> exchange.
 * The API is stateless, so the intent travels in an encrypted, expiring `state`.
 */
class GoogleAuthController extends Controller
{
    use RespondsWithToken;

    private const STATE_TTL_SECONDS = 600;

    private const CODE_TTL_SECONDS = 300;

    public function redirect(GoogleRedirectRequest $request): RedirectResponse
    {
        $state = Crypt::encryptString(json_encode([
            'intent' => $request->validated('intent'),
            'timezone' => $request->validated('timezone'),
            'expires' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
            'nonce' => Str::random(16),
        ]));

        return $this->google()
            ->stateless()
            ->scopes(['openid', 'email', 'profile'])
            ->with(['state' => $state, 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $this->readState($request->query('state'));

        if ($state === null || $request->has('error')) {
            return $this->toFrontend(['error' => 'google_failed']);
        }

        try {
            $google = $this->google()->stateless()->user();
        } catch (Throwable) {
            Log::warning('Google sign-in provider request failed.');

            return $this->toFrontend(['error' => 'google_failed']);
        }

        $email = strtolower((string) $google->getEmail());
        $subject = (string) $google->getId();
        $emailVerified = ($google->user['email_verified'] ?? false) === true;

        if ($subject === '') {
            return $this->toFrontend(['error' => 'google_failed']);
        }

        if ($email === '' || ! $emailVerified) {
            return $this->toFrontend(['error' => 'email_not_verified']);
        }

        try {
            $params = DB::transaction(function () use ($google, $email, $subject, $state) {
                // Resolve one account from one snapshot, with an existing subject taking precedence.
                $id = User::where('google_id', $subject)->orWhere('email', $email)
                    ->orderByRaw('case when google_id = ? then 0 else 1 end', [$subject])
                    ->value('id');

                $signingIn = $state['intent'] === 'sign_in';
                // If this UUID disappeared while waiting, never fall back to another email match.
                $user = $signingIn && $id !== null ? User::lockForUpdate()->find($id) : null;
                if ($state['expires'] < now()->getTimestamp()) {
                    return ['error' => 'google_failed'];
                }

                if ($signingIn) {
                    if (! $user) {
                        return ['error' => 'account_not_found'];
                    }

                    if ($user->google_id !== null && $user->google_id !== $subject) {
                        return ['error' => 'google_failed'];
                    }
                } else {
                    if ($id !== null) {
                        return ['error' => 'account_already_exists'];
                    }

                    $user = User::create([
                        'name' => $google->getName() ?: Str::before($email, '@'),
                        'email' => $email,
                        'timezone' => $state['timezone'] ?: 'UTC',
                    ]);
                    $user->deletePasswordResetToken();
                }

                $code = Str::random(64);
                if (! $user->forceFill([
                    'google_id' => $subject,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'google_exchange_digest' => hash('sha256', $code),
                    'google_exchange_expires_at' => now()->addSeconds(self::CODE_TTL_SECONDS),
                ])->save()) {
                    throw new RuntimeException('Google exchange grant creation was cancelled.');
                }

                return ['code' => $code];
            });
        } catch (UniqueConstraintViolationException) {
            // Another signup or link won. Reusing it would change the callback's intent or identity.
            $params = ['error' => $state['intent'] === 'sign_up' ? 'account_already_exists' : 'google_failed'];
        }

        return $this->toFrontend($params);
    }

    public function exchange(GoogleExchangeRequest $request): JsonResponse
    {
        $digest = hash('sha256', $request->validated('code'));

        return DB::transaction(function () use ($digest) {
            $user = User::where('google_exchange_digest', $digest)->lockForUpdate()->first();

            if (! $user || ! $user->google_exchange_expires_at?->isFuture()) {
                throw ValidationException::withMessages(['code' => [__('google.code_invalid')]]);
            }

            if (! $user->forceFill([
                'google_exchange_digest' => null,
                'google_exchange_expires_at' => null,
            ])->save()) {
                throw new RuntimeException('Google exchange grant consumption was cancelled.');
            }

            return $this->tokenResponse($user);
        });
    }

    /**
     * @return array{intent: string, timezone: ?string, expires: int}|null
     */
    private function readState(mixed $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        $expires = $data['expires'] ?? null;
        if (! in_array($data['intent'] ?? null, ['sign_in', 'sign_up'], true) || ! is_int($expires) || $expires < now()->getTimestamp()) {
            return null;
        }

        return ['intent' => $data['intent'], 'timezone' => $data['timezone'] ?? null, 'expires' => $expires];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function toFrontend(array $params): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/auth/google/callback?'.http_build_query($params));
    }

    private function google(): GoogleProvider
    {
        $driver = Socialite::driver('google');
        assert($driver instanceof GoogleProvider);

        return $driver;
    }
}
