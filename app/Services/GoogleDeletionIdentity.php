<?php

namespace App\Services;

use App\Models\GoogleDeletionChallenge;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GoogleDeletionIdentity
{
    public const MAX_AUTH_AGE = 300;

    private const CLOCK_SKEW = 30;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public function authorizationUrl(string $state, string $nonce, string $pkceVerifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid',
            'access_type' => 'online',
            'prompt' => 'select_account',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge_method' => 'S256',
            'code_challenge' => self::digestVerifier($pkceVerifier),
            'claims' => '{"id_token":{"auth_time":{"essential":true}}}',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{auth_time: int, exp: int} */
    public function verify(
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $pkceVerifier,
        GoogleDeletionChallenge $challenge,
    ): array {
        try {
            $provider = Http::acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
            $exchange = $provider->asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'code_verifier' => $pkceVerifier,
            ]);
            if ($exchange->status() === 400 && $exchange->json('error') === 'invalid_grant') {
                throw self::invalidProof();
            }
            if (! $exchange->successful()) {
                throw $this->unavailable();
            }
            $idToken = $exchange->json('id_token');
            if (! is_string($idToken) || $idToken === '' || strlen($idToken) > 16_384) {
                throw self::invalidProof();
            }

            $jwks = $provider->get(self::JWKS_URL);
            $keys = $jwks->json('keys');
            if (! $jwks->successful() || ! is_array($keys)) {
                throw $this->unavailable();
            }
            $rsaKeys = array_filter($keys, static fn ($key) => is_array($key)
                && ($key['kty'] ?? null) === 'RSA'
                && ($key['use'] ?? 'sig') === 'sig'
                && ($key['alg'] ?? 'RS256') === 'RS256');
            try {
                $signingKeys = JWK::parseKeySet(['keys' => $rsaKeys], 'RS256');
            } catch (Throwable) {
                throw $this->unavailable();
            }
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        $now = now()->timestamp;
        [$previousTimestamp, $previousLeeway] = [JWT::$timestamp, JWT::$leeway];
        try {
            // JWT::decode is synchronous. Limit skew to this verification.
            JWT::$timestamp = $now;
            JWT::$leeway = self::CLOCK_SKEW;
            $claims = JWT::decode($idToken, $signingKeys);
        } catch (Throwable) {
            throw self::invalidProof();
        } finally {
            JWT::$timestamp = $previousTimestamp;
            JWT::$leeway = $previousLeeway;
        }

        $clientId = config('services.google.client_id');
        $issuedAt = $claims->iat ?? null;
        $expiry = $claims->exp ?? null;
        $authTime = $claims->auth_time ?? null;
        $nonce = $claims->nonce ?? null;
        if (! in_array($claims->iss ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims->aud ?? null) !== $clientId
            || (property_exists($claims, 'azp') && $claims->azp !== $clientId)
            || ($claims->sub ?? null) !== $challenge->google_subject
            || ! is_string($nonce)
            || ! hash_equals((string) $challenge->nonce_digest, hash('sha256', $nonce))
            || ! is_int($expiry) || $expiry <= $now
            || ! is_int($issuedAt) || $issuedAt <= 0 || $issuedAt >= $expiry || $issuedAt > $now + self::CLOCK_SKEW
            || ! is_int($authTime) || $authTime <= 0 || $authTime <= $now - self::MAX_AUTH_AGE
            || $authTime > $now + self::CLOCK_SKEW || $authTime > $issuedAt + self::CLOCK_SKEW) {
            throw self::invalidProof();
        }

        return ['auth_time' => $authTime, 'exp' => $expiry];
    }

    public static function randomSecret(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function digestVerifier(#[\SensitiveParameter] string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function invalidProof(): ValidationException
    {
        return ValidationException::withMessages(['google_confirmation' => [__('account.invalid_google_confirmation')]]);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function redirectUri(): string
    {
        return config('app.frontend_url').'/auth/google/deletion/callback';
    }

    private function unavailable(): HttpException
    {
        return new HttpException(503, __('account.google_deletion_unavailable'));
    }
}
