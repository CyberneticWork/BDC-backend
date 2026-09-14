<?php

namespace App\Services;

class HrJwtToken
{
    public static function isJwt(?string $token): bool
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }

        [$header] = explode('.', $token, 2);
        $decoded = json_decode(self::b64urlDecode($header), true);

        return is_array($decoded) && ($decoded['typ'] ?? '') === 'JWT' && ($decoded['alg'] ?? '') === 'HS256';
    }

    public static function encode(array $payload): string
    {
        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = self::b64url(hash_hmac('sha256', $header.'.'.$body, self::secret(), true));

        return $header.'.'.$body.'.'.$sig;
    }

    public static function decode(string $token): ?array
    {
        if (!self::isJwt($token)) {
            return null;
        }

        [$header, $body, $sig] = explode('.', $token, 3);
        $expected = self::b64url(hash_hmac('sha256', $header.'.'.$body, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < $now) {
            return null;
        }
        if (!isset($payload['sub']) || $payload['sub'] === '' || !isset($payload['jti']) || $payload['jti'] === '') {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        $secret = (string) config('auth.jwt_secret', config('app.key'));

        return $secret !== '' ? $secret : (string) config('app.key');
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
