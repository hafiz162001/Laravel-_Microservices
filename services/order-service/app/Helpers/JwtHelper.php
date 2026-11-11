<?php

namespace App\Helpers;

class JwtHelper
{
    /**
     * Decode and verify JWT. Returns payload if valid, null otherwise.
     */
    public static function decode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header64, $payload64, $sig64] = $parts;

        // Verify signature
        $expectedSig = hash_hmac('sha256', $header64 . "." . $payload64, $secret, true);
        if (!hash_equals(self::base64UrlDecode($sig64), $expectedSig)) {
            return null; // Invalid signature
        }

        $payload = json_decode(self::base64UrlDecode($payload64), true);
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expired or malformed
        }

        return $payload;
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
}

