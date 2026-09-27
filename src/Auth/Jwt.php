<?php

declare(strict_types=1);

namespace Medico\Auth;

use RuntimeException;

/**
 * Lightweight HS256 JWT (no external deps).
 */
final class Jwt
{
    public static function encode(array $payload, string $secret, int $ttl, string $issuer): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = array_merge($payload, [
            'iss' => $issuer,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $segments = [
            self::b64(json_encode($header, JSON_THROW_ON_ERROR)),
            self::b64(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signing = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing, $secret, true);
        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret, string $issuer): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format');
        }

        [$h64, $p64, $s64] = $parts;
        $signing = $h64 . '.' . $p64;
        $expected = self::b64(hash_hmac('sha256', $signing, $secret, true));

        if (!hash_equals($expected, $s64)) {
            throw new RuntimeException('Invalid token signature');
        }

        $payload = json_decode(self::ub64($p64), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid token payload');
        }
        if (($payload['iss'] ?? '') !== $issuer) {
            throw new RuntimeException('Invalid token issuer');
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expired');
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
