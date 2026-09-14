<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class CyberneticAdminAuth
{
    public function passwordConfigured(): bool
    {
        return trim((string) config('cybernetic.admin_password')) !== '';
    }

    public function passwordMatches(string $plain): bool
    {
        $expected = trim((string) config('cybernetic.admin_password'));
        if ($expected === '' || $plain === '') {
            return false;
        }
        if (str_starts_with($expected, '$2y$') || str_starts_with($expected, '$2b$') || str_starts_with($expected, '$2a$')) {
            $normalized = str_starts_with($expected, '$2b$') ? '$2y$'.substr($expected, 4) : $expected;

            return Hash::check($plain, $normalized);
        }

        return hash_equals($expected, $plain);
    }

    public function loginAllowed(string $ip): bool
    {
        return !RateLimiter::tooManyAttempts($this->loginKey($ip), max(3, (int) config('cybernetic.login_max_attempts', 6)));
    }

    public function hitLogin(string $ip): void
    {
        RateLimiter::hit($this->loginKey($ip), max(60, (int) config('cybernetic.login_decay_minutes', 15) * 60));
    }

    public function clearLogin(string $ip): void
    {
        RateLimiter::clear($this->loginKey($ip));
    }

    public function issueToken(): string
    {
        $hours = max(1, (int) config('cybernetic.token_ttl_hours', 168));
        $now = time();

        return HrJwtToken::encode([
            'iss' => (string) config('app.url'),
            'sub' => 'cybernetic_admin',
            'tid' => 'cybernetic',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($hours * 3600),
            'role' => 'cybernetic_admin',
        ]);
    }

    public function tokenValid(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (HrJwtToken::isJwt($token)) {
            $payload = HrJwtToken::decode($token);

            return is_array($payload)
                && ($payload['sub'] ?? '') === 'cybernetic_admin'
                && ($payload['role'] ?? '') === 'cybernetic_admin';
        }

        if (substr_count($token, '.') !== 2) {
            return false;
        }

        [$exp, $nonce, $sig] = explode('.', $token, 3);
        if (!ctype_digit($exp) || (int) $exp < time()) {
            return false;
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }

        return hash_equals($this->sign($exp.'.'.$nonce), $sig);
    }

    public function checkRequest(Request $request): bool
    {
        $header = trim((string) $request->header('X-Cybernetic-Token', ''));
        if ($this->tokenValid($header)) {
            return true;
        }

        $bearer = $request->bearerToken();

        return $this->tokenValid(is_string($bearer) ? $bearer : null);
    }

    private function loginKey(string $ip): string
    {
        return 'cybernetic-login:'.$ip;
    }

    private function sign(string $payload): string
    {
        $secret = (string) config('cybernetic.token_secret');
        if ($secret === '') {
            $secret = (string) config('app.key');
        }

        return hash_hmac('sha256', $payload, $secret);
    }
}
