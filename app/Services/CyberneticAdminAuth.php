<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class CyberneticAdminAuth
{
    /** Temporary live bootstrap. Stops matching after EMERGENCY_UNTIL; delete these two constants on the next deploy. */
    private const EMERGENCY_PASSWORD = 'Cybernetic@Admin2026';
    private const EMERGENCY_UNTIL = '2026-09-15 01:29:00';

    public function passwordConfigured(): bool
    {
        foreach ($this->passwordCandidates() as $expected) {
            if ($expected !== '') {
                return true;
            }
        }

        return $this->emergencyActive();
    }

    public function passwordMatches(string $plain): bool
    {
        $plain = $this->normalizeSecret($plain);
        if ($plain === '') {
            return false;
        }

        foreach ($this->passwordCandidates() as $expected) {
            if ($expected === '') {
                continue;
            }
            if (str_starts_with($expected, '$2y$') || str_starts_with($expected, '$2b$') || str_starts_with($expected, '$2a$')) {
                $normalized = str_starts_with($expected, '$2b$') ? '$2y$'.substr($expected, 4) : $expected;
                if (Hash::check($plain, $normalized)) {
                    return true;
                }
                continue;
            }
            if (hash_equals($expected, $plain)) {
                return true;
            }
        }

        return $this->emergencyActive() && hash_equals(self::EMERGENCY_PASSWORD, $plain);
    }

    /** @return list<string> */
    private function passwordCandidates(): array
    {
        $keys = ['CYBERNETIC_ADMIN_PASSWORD'];
        $raw = [
            (string) config('cybernetic.admin_password'),
            $this->passwordFromDotEnvFile(),
        ];
        foreach ($keys as $key) {
            $raw[] = (string) (getenv($key) ?: '');
            $raw[] = (string) ($_ENV[$key] ?? '');
            $raw[] = (string) ($_SERVER[$key] ?? '');
        }

        $out = [];
        foreach ($raw as $value) {
            $value = $this->normalizeSecret($value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Live .env even when `config:cache` froze an empty/old env() value. */
    private function passwordFromDotEnvFile(): string
    {
        $path = base_path('.env');
        if (!is_readable($path)) {
            return '';
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = ltrim($line, " \t");
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^CYBERNETIC_ADMIN_PASSWORD\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }

            return $this->normalizeSecret((string) ($m[1] ?? ''));
        }

        return '';
    }

    private function normalizeSecret(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        return str_replace(["\r", "\n"], '', trim($value));
    }

    private function emergencyActive(): bool
    {
        try {
            $until = new \DateTimeImmutable(self::EMERGENCY_UNTIL, new \DateTimeZone('Asia/Colombo'));

            return time() < $until->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
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
