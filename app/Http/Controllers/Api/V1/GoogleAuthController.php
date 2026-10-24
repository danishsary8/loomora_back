<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleExchangeRequest;
use App\Http\Requests\Auth\GoogleRedirectRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in for an API on another domain than the frontend (docs/09):
 * redirect -> Google -> callback -> frontend with a one-time code -> exchange.
 * The API is stateless, so the intent travels in an encrypted, expiring `state`.
 */
class GoogleAuthController extends Controller
{
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

        return Socialite::driver('google')
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
            $google = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            report($e);

            return $this->toFrontend(['error' => 'google_failed']);
        }

        $email = strtolower((string) $google->getEmail());
        $emailVerified = (bool) ($google->user['email_verified'] ?? false);

        if ($email === '' || ! $emailVerified) {
            return $this->toFrontend(['error' => 'email_not_verified']);
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::where('email', $email)->first();

        if ($state['intent'] === 'sign_in' && ! $user) {
            return $this->toFrontend(['error' => 'account_not_found']);
        }

        if ($state['intent'] === 'sign_up' && $user) {
            return $this->toFrontend(['error' => 'account_already_exists']);
        }

        if (! $user) {
            $user = User::create([
                'name' => $google->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'timezone' => $state['timezone'] ?: 'UTC',
            ]);
        }

        // Google confirmed this address, so it counts as verified, and the account is linked.
        $user->forceFill([
            'google_id' => $user->google_id ?? $google->getId(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), $user->id, self::CODE_TTL_SECONDS);

        return $this->toFrontend(['code' => $code]);
    }

    public function exchange(GoogleExchangeRequest $request): JsonResponse
    {
        // pull() makes the code single-use.
        $userId = Cache::pull($this->cacheKey($request->validated('code')));
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            throw ValidationException::withMessages(['code' => [__('google.code_invalid')]]);
        }

        $token = $user->createToken('api', ['*'], now()->addMinutes(config('sanctum.expiration')));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at->toIso8601ZuluString(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * @return array{intent: string, timezone: ?string}|null
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

        if (! in_array($data['intent'] ?? null, ['sign_in', 'sign_up'], true) || ($data['expires'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return ['intent' => $data['intent'], 'timezone' => $data['timezone'] ?? null];
    }

    /**
     * @param  array<string, string>  $params
     */
    private function toFrontend(array $params): RedirectResponse
    {
        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/auth/google/callback?'.http_build_query($params));
    }

    private function cacheKey(string $code): string
    {
        return 'google-code:'.hash('sha256', $code);
    }
}
