<?php

namespace App\Services;

use App\Http\Controllers\Concerns\WithActiveAccount;
use App\Models\GoogleDeletionChallenge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

class GoogleDeletionProof
{
    use WithActiveAccount;

    public const BROWSER_SECRET = 'regex:/\A[A-Za-z0-9_-]{42}[AEIMQUYcgkosw048]\z/';

    public function __construct(private GoogleDeletionIdentity $identity) {}

    /** @return array{id: string, authorization_url: string, expires_at: string} */
    public function start(User $authenticated, string $browserChallenge): array
    {
        return $this->withActiveAccount($authenticated, function (User $user) use ($browserChallenge) {
            $tokenId = $this->eligibleTokenId($user);
            $state = GoogleDeletionIdentity::randomSecret();
            $nonce = GoogleDeletionIdentity::randomSecret();
            $pkceVerifier = GoogleDeletionIdentity::randomSecret();

            GoogleDeletionChallenge::where('user_id', $user->id)->delete();
            $challenge = GoogleDeletionChallenge::create([
                'user_id' => $user->id,
                'personal_access_token_id' => $tokenId,
                'google_subject' => $user->google_id,
                'purpose' => GoogleDeletionChallenge::PURPOSE,
                'browser_challenge' => $browserChallenge,
                'state_digest' => hash('sha256', $state),
                'nonce_digest' => hash('sha256', $nonce),
                'pkce_verifier' => $pkceVerifier,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(5),
            ]);

            return [
                'id' => $challenge->id,
                'authorization_url' => $this->identity->authorizationUrl($state, $nonce, $pkceVerifier),
                'expires_at' => $challenge->expires_at->toIso8601ZuluString(),
            ];
        });
    }

    /** @return array{id: string, expires_at: string} */
    public function verify(
        User $authenticated,
        string $id,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $state,
        #[\SensitiveParameter] string $browserVerifier,
    ): array {
        [$reservation, $pkceVerifier] = $this->withActiveAccount($authenticated, function (User $user) use ($id, $state, $browserVerifier) {
            $challenge = $this->boundChallenge($user, $id, $browserVerifier, 'pending');
            $pkceVerifier = $challenge->pkce_verifier;
            if ($pkceVerifier === null || ! hash_equals((string) $challenge->state_digest, hash('sha256', $state))) {
                throw GoogleDeletionIdentity::invalidProof();
            }

            $challenge->update(['status' => 'verifying', 'state_digest' => null, 'pkce_verifier' => null]);

            return [$challenge, $pkceVerifier];
        });

        try {
            $claims = $this->identity->verify($code, $pkceVerifier, $reservation);

            return $this->withActiveAccount($authenticated, function (User $user) use ($id, $browserVerifier, $claims) {
                $challenge = $this->boundChallenge($user, $id, $browserVerifier, 'verifying');
                $verifiedAt = now();
                $expiresAt = Carbon::createFromTimestampUTC(min(
                    $challenge->expires_at->timestamp,
                    $verifiedAt->timestamp + 120,
                    $claims['auth_time'] + GoogleDeletionIdentity::MAX_AUTH_AGE,
                    $claims['exp'],
                ));
                if ($expiresAt->lte($verifiedAt)) {
                    throw GoogleDeletionIdentity::invalidProof();
                }

                $challenge->update([
                    'status' => 'confirmed',
                    'nonce_digest' => null,
                    'verified_at' => $verifiedAt,
                    'confirmation_expires_at' => $expiresAt,
                ]);

                return ['id' => $challenge->id, 'expires_at' => $expiresAt->toIso8601ZuluString()];
            });
        } catch (Throwable $failure) {
            GoogleDeletionChallenge::where('user_id', $authenticated->id)->whereKey($id)
                ->where('status', 'verifying')->update(['status' => 'failed', 'nonce_digest' => null]);
            throw $failure;
        }
    }

    /** Called inside the account transaction, before deleteWithData. */
    public function consume(User $user, string $id, #[\SensitiveParameter] string $browserVerifier): void
    {
        $challenge = $this->boundChallenge($user, $id, $browserVerifier, 'confirmed');
        if ($challenge->confirmation_expires_at === null || $challenge->confirmation_expires_at->lte(now())) {
            throw GoogleDeletionIdentity::invalidProof();
        }

        $challenge->update(['status' => 'consumed']);
    }

    private function boundChallenge(
        User $user,
        string $id,
        #[\SensitiveParameter] string $browserVerifier,
        string $status,
    ): GoogleDeletionChallenge {
        $tokenId = $this->eligibleTokenId($user);
        $challenge = GoogleDeletionChallenge::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
        if ($challenge === null
            || $challenge->purpose !== GoogleDeletionChallenge::PURPOSE
            || $challenge->status !== $status
            || $challenge->expires_at->lte(now())
            || $challenge->personal_access_token_id !== $tokenId
            || $challenge->google_subject !== $user->google_id
            || ! hash_equals($challenge->browser_challenge, GoogleDeletionIdentity::digestVerifier($browserVerifier))) {
            throw GoogleDeletionIdentity::invalidProof();
        }

        return $challenge;
    }

    private function eligibleTokenId(User $user): int
    {
        $tokenId = data_get($user->currentAccessToken(), 'id');
        if ($user->password !== null || blank($user->google_id) || ! is_int($tokenId)) {
            throw GoogleDeletionIdentity::invalidProof();
        }

        return $tokenId;
    }
}
