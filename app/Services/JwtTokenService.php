<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class JwtTokenService
{
    public function issue(User $user): string
    {
        $now = time();
        $ttl = max(300, (int) config('jwt.ttl', 28800));
        $payload = [
            'iss' => (string) config('jwt.issuer'),
            'sub' => (int) $user->id,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return $this->encode($payload);
    }

    public function userFromBearer(?string $bearer): ?User
    {
        $payload = $this->decode($bearer);
        if (!$payload) {
            return null;
        }

        $jti = (string) ($payload['jti'] ?? '');
        try {
            if ($jti !== '' && Cache::get($this->denylistKey($jti))) {
                return null;
            }
        } catch (\Throwable $e) {
            // Cache outage must not block valid tokens.
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId < 1) {
            return null;
        }

        return User::query()->find($userId);
    }

    public function revokeBearer(?string $bearer): void
    {
        $payload = $this->decode($bearer);
        if (!$payload) {
            return;
        }
        $jti = (string) ($payload['jti'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        if ($jti === '') {
            return;
        }
        $seconds = max(1, $exp - time());
        Cache::put($this->denylistKey($jti), 1, $seconds);
    }

    public function isJwt(?string $token): bool
    {
        if (!$token) {
            return false;
        }

        return substr_count($token, '.') === 2 && !str_contains($token, '|');
    }

    private function encode(array $payload): string
    {
        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $body = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->b64url(hash_hmac('sha256', $header . '.' . $body, $this->secret(), true));

        return $header . '.' . $body . '.' . $signature;
    }

    private function decode(?string $token): ?array
    {
        if (!$this->isJwt($token)) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = explode('.', $token, 3);
        $expected = $this->b64url(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $this->secret(), true));
        if (!hash_equals($expected, $signatureB64)) {
            return null;
        }

        $payload = json_decode($this->b64urlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        $now = time();
        if (($payload['nbf'] ?? 0) > $now + 30) {
            return null;
        }
        if (($payload['exp'] ?? 0) < $now) {
            return null;
        }

        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');
        if ($secret === '') {
            $secret = (string) config('app.key');
        }
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }

        return $secret;
    }

    private function denylistKey(string $jti): string
    {
        return 'jwt:deny:' . $jti;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
