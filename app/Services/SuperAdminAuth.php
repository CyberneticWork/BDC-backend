<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminAuth
{
    public function passwordConfigured(): bool
    {
        foreach ($this->passwordCandidates() as $expected) {
            if ($expected !== '') {
                return true;
            }
        }

        return false;
    }

    public function identifierMatches(string $identifier): bool
    {
        $value = strtolower(trim($identifier));
        if ($value === '') {
            return false;
        }

        $aliases = [
            strtolower($this->email()),
            'superadmin',
            'super-admin',
            'super_admin',
        ];

        return in_array($value, $aliases, true);
    }

    public function passwordMatches(string $plain): bool
    {
        $plain = $this->normalizeSecret($plain);
        if ($plain === '' || !$this->passwordConfigured()) {
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

        return false;
    }

    public function attempt(string $identifier, string $password): bool
    {
        return $this->identifierMatches($identifier) && $this->passwordMatches($password);
    }

    public function email(): string
    {
        $email = $this->normalizeSecret((string) config('cybernetic.super_admin_email', 'superadmin@cybernetic.local'));

        return $email !== '' ? strtolower($email) : 'superadmin@cybernetic.local';
    }

    public function virtualUser(): User
    {
        $user = new User();
        $user->forceFill([
            'id' => 999999001,
            'name' => 'Super Admin',
            'email' => $this->email(),
            'role' => 'admin',
            'is_first_login' => false,
        ]);
        $user->syncOriginal();
        $user->exists = false;
        $user->setAttribute('is_super_admin', true);

        return $user;
    }

    public function isSuperAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return (bool) $user->getAttribute('is_super_admin')
            || strtolower((string) $user->role) === 'super_admin';
    }

    /** @return list<string> */
    private function passwordCandidates(): array
    {
        $raw = [
            (string) config('cybernetic.super_admin_password'),
            $this->passwordFromDotEnvFile('SUPER_ADMIN_PASSWORD'),
            (string) (getenv('SUPER_ADMIN_PASSWORD') ?: ''),
            (string) ($_ENV['SUPER_ADMIN_PASSWORD'] ?? ''),
            (string) ($_SERVER['SUPER_ADMIN_PASSWORD'] ?? ''),
            (string) config('cybernetic.admin_password'),
            $this->passwordFromDotEnvFile('CYBERNETIC_ADMIN_PASSWORD'),
        ];

        $out = [];
        foreach ($raw as $value) {
            $value = $this->normalizeSecret($value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function passwordFromDotEnvFile(string $key): string
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
            if (!preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m)) {
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
}
