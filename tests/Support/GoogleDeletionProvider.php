<?php

namespace Tests\Support;

use Firebase\JWT\JWT;

class GoogleDeletionProvider
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: array} */
    public static function assertion(string $nonce, array $overrides = [], string $algorithm = 'RS256', string $keyId = 'test-key'): array
    {
        static $signer;
        $signer ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($signer);
        $now = now()->timestamp;

        $claims = array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => 'synthetic-client',
            'azp' => 'synthetic-client',
            'sub' => 'linked-subject',
            'nonce' => $nonce,
            'iat' => $now,
            'exp' => $now + 300,
            'auth_time' => $now - 10,
            'email' => 'changed@example.test',
        ], $overrides);

        return [
            JWT::encode(array_filter($claims, fn ($claim) => $claim !== null), $algorithm === 'RS256' ? $signer : str_repeat('s', 32), $algorithm, $keyId),
            ['keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => self::base64Url($details['rsa']['n']),
                'e' => self::base64Url($details['rsa']['e']),
            ]]],
        ];
    }
}
