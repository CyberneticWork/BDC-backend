<?php

namespace App\Services;

use Illuminate\Http\Request;

class CyberneticAdminAuth
{
    public function passwordMatches(string $plain): bool
    {
        $expected = (string) config('cybernetic.admin_password');
        if ($expected === '' || $plain === '') {
            return false;
        }

        return hash_equals($expected, $plain);
    }

    public function issueToken(): string
    {
        $hours = max(1, (int) config('cybernetic.token_ttl_hours', 12));
        $exp = now()->addHours($hours)->timestamp;
        $nonce = bin2hex(random_bytes(16));
        $payload = $exp . '.' . $nonce;

        return $payload . '.' . $this->sign($payload);
    }

    public function tokenValid(?string $token): bool
    {
        if (!is_string($token) || substr_count($token, '.') !== 2) {
            return false;
        }

        [$exp, $nonce, $sig] = explode('.', $token, 3);
        if (!ctype_digit($exp) || (int) $exp < time()) {
            return false;
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }

        return hash_equals($this->sign($exp . '.' . $nonce), $sig);
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

    private function sign(string $payload): string
    {
        $secret = (string) config('cybernetic.token_secret');
        if ($secret === '') {
            $secret = (string) config('app.key');
        }

        return hash_hmac('sha256', $payload, $secret);
    }
}
