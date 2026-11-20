<?php

namespace App\Support;

use App\Exceptions\InvalidFrontendOrigin;

class FrontendOrigin
{
    public static function validate(#[\SensitiveParameter] mixed $value): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidFrontendOrigin;
        }

        $parts = parse_url($value);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $origin = strtolower($scheme.'://'.$host).($port === null || $port === $defaultPort ? '' : ':'.$port);

        $usesAllowedScheme = $scheme === 'https'
            || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));

        if (! $usesAllowedScheme || $value !== $origin || ! self::hasCanonicalHost($host)) {
            throw new InvalidFrontendOrigin;
        }

        return $value;
    }

    private static function hasCanonicalHost(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            $address = inet_pton(substr($host, 1, -1));

            return $address !== false && $host === '['.self::serializeIpv6($address).']';
        }

        // Browsers treat domains ending in a number or hex literal as IPv4.
        if (preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)\.?$/D', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return idn_to_ascii($host, IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46) === $host;
    }

    private static function serializeIpv6(string $address): string
    {
        $ip = inet_ntop($address);
        assert($ip !== false);

        if (! str_contains($ip, '.')) {
            return $ip;
        }

        // inet_ntop can emit a dotted IPv4 suffix. Browser origins use hex.
        $prefix = substr($ip, 0, strrpos($ip, ':') + 1);
        $hex = bin2hex(substr($address, -4));
        $upper = ltrim(substr($hex, 0, 4), '0');
        $lower = ltrim(substr($hex, 4), '0') ?: '0';

        if ($prefix === '::' && $upper === '') {
            return $prefix.$lower;
        }

        return $prefix.($upper ?: '0').':'.$lower;
    }
}
