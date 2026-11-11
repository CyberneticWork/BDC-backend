<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class UserActivityLogger
{
    private static bool $ensured = false;

    public static function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;
        if (Schema::hasTable('user_activity_logs')) {
            return;
        }
        try {
            Schema::create('user_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name', 190)->nullable();
                $table->string('user_role', 60)->nullable();
                $table->string('action', 60)->index();
                $table->string('module', 80)->index();
                $table->string('method', 10)->nullable();
                $table->string('path', 255)->nullable();
                $table->string('subject_type', 120)->nullable();
                $table->string('subject_id', 80)->nullable();
                $table->string('description', 500)->nullable();
                $table->json('meta')->nullable();
                $table->unsignedSmallInteger('status_code')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        } catch (\Throwable $e) {
            // another process may have created it
        }
    }

    public static function log(array $attrs, ?Request $request = null, ?User $user = null): void
    {
        try {
            self::ensureTable();
            if (!Schema::hasTable('user_activity_logs')) {
                return;
            }

            $request = $request ?: request();
            $user = $user ?: ($request?->user() ?: Auth::user());

            $name = null;
            $role = null;
            $userId = null;
            if ($user) {
                $userId = (int) ($user->id ?? 0) ?: null;
                $name = $user->name ?? $user->email ?? null;
                $role = (string) ($user->role ?? '');
                if ($user->getAttribute('is_super_admin')) {
                    $role = 'super_admin';
                }
            }

            $meta = $attrs['meta'] ?? null;
            if (is_array($meta)) {
                $meta = self::redact($meta);
            }

            UserActivityLog::create([
                'user_id' => $attrs['user_id'] ?? $userId,
                'user_name' => $attrs['user_name'] ?? $name,
                'user_role' => $attrs['user_role'] ?? ($role !== '' ? $role : null),
                'action' => (string) ($attrs['action'] ?? 'action'),
                'module' => (string) ($attrs['module'] ?? 'system'),
                'method' => $attrs['method'] ?? ($request?->method()),
                'path' => self::shortPath($attrs['path'] ?? ($request?->path())),
                'subject_type' => $attrs['subject_type'] ?? null,
                'subject_id' => isset($attrs['subject_id']) ? (string) $attrs['subject_id'] : null,
                'description' => self::clip($attrs['description'] ?? null, 500),
                'meta' => $meta,
                'status_code' => $attrs['status_code'] ?? null,
                'ip' => $attrs['ip'] ?? $request?->ip(),
                'user_agent' => self::clip($attrs['user_agent'] ?? $request?->userAgent(), 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // never block business flow
        }
    }

    public static function fromHttp(Request $request, int $statusCode): void
    {
        $method = strtoupper($request->method());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $path = self::normalizePath($request);
        if (self::shouldSkip($path, $method)) {
            return;
        }

        [$module, $action, $subjectType, $subjectId, $description] = self::classify($method, $path, $request, $statusCode);

        self::log([
            'action' => $action,
            'module' => $module,
            'method' => $method,
            'path' => $path,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'status_code' => $statusCode,
            'meta' => [
                'query' => self::redact($request->query()),
                'body_keys' => array_values(array_diff(array_keys($request->all()), self::sensitiveKeys())),
            ],
        ], $request);
    }

    public static function authEvent(string $action, ?User $user, Request $request, string $description, array $meta = []): void
    {
        self::log([
            'action' => $action,
            'module' => 'auth',
            'method' => $request->method(),
            'path' => self::normalizePath($request),
            'description' => $description,
            'status_code' => $action === 'login_failed' ? 401 : 200,
            'meta' => $meta,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $user?->email,
            'user_role' => $user
                ? (($user->getAttribute('is_super_admin') ? 'super_admin' : (string) $user->role) ?: null)
                : null,
        ], $request, $user);
    }

    private static function normalizePath(Request $request): string
    {
        $path = preg_replace('#^index\.php/#', '', trim($request->path(), '/')) ?? '';

        return self::shortPath($path);
    }

    private static function shortPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        return self::clip($path, 255);
    }

    private static function shouldSkip(string $path, string $method): bool
    {
        $skipExact = [
            'login',
            'login/otp',
            'send-otp',
            'me/push-token',
            'hikvision/cloud-base',
        ];
        if (in_array($path, $skipExact, true)) {
            return true;
        }

        $skipPrefixes = [
            'public/',
            'hikvision/punches/',
            'hikvision/webhook/',
            'zkteco/punches/',
            'zkteco/iclock/',
            'notifications/mark',
        ];
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        // noisy polling / read-only-ish posts
        if ($method === 'POST' && preg_match('#^(notifications|me/notices)#', $path)) {
            return true;
        }

        return false;
    }

    /** @return array{0:string,1:string,2:?string,3:?string,4:string} */
    private static function classify(string $method, string $path, Request $request, int $statusCode): array
    {
        $action = match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($method),
        };

        $module = 'api';
        $subjectType = null;
        $subjectId = null;

        $map = [
            'leave' => 'leave',
            'leaves' => 'leave',
            'salary-advance' => 'salary_advance',
            'advances' => 'salary_advance',
            'me/advances' => 'salary_advance',
            'loans' => 'loan',
            'employees' => 'employee',
            'employee' => 'employee',
            'salary' => 'salary',
            'salaries' => 'salary',
            'roster' => 'roster',
            'time-card' => 'time_card',
            'timecard' => 'time_card',
            'time_cards' => 'time_card',
            'users' => 'users',
            'acl' => 'acl',
            'companies' => 'settings',
            'departments' => 'settings',
            'cybernetic-admin' => 'cybernetic_admin',
            'zkteco' => 'biometric',
            'hikvision' => 'biometric',
            'resignations' => 'resignation',
            'medical' => 'medical',
            'weekly-off' => 'weekly_off',
            'allowance' => 'allowance',
            'deduction' => 'deduction',
            'reports' => 'reports',
        ];

        foreach ($map as $needle => $mod) {
            if (str_contains($path, $needle)) {
                $module = $mod;
                break;
            }
        }

        if (preg_match('#/(approve|reject|review|status|approval)#i', $path) || str_contains(strtolower((string) $request->input('action')), 'approv')) {
            $action = 'approve';
        }
        if (preg_match('#reject#i', $path) || strtolower((string) $request->input('action')) === 'reject') {
            $action = 'reject';
        }
        if (str_contains($path, 'login') || str_contains($path, 'password')) {
            $module = 'auth';
            $action = str_contains($path, 'password') ? 'password_change' : $action;
        }

        if (preg_match('#/(\d+)(?:/|$)#', $path, $m)) {
            $subjectId = $m[1];
        }

        $label = str_replace(['-', '_'], ' ', $module);
        $verb = $action;
        $who = $request->user()?->name ?: $request->user()?->email ?: 'User';
        $ok = $statusCode >= 200 && $statusCode < 400;
        $description = sprintf(
            '%s %s %s%s',
            $who,
            $verb,
            $label,
            $ok ? '' : " (HTTP {$statusCode})"
        );

        return [$module, $action, $subjectType ?: $module, $subjectId, self::clip($description, 500)];
    }

    private static function sensitiveKeys(): array
    {
        return [
            'password',
            'current_password',
            'new_password',
            'new_password_confirmation',
            'password_confirmation',
            'otp',
            'token',
            'access_token',
            'secret',
            'private_key',
        ];
    }

    private static function redact($data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::sensitiveKeys(), true)) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::redact($value);
            } else {
                $out[$key] = is_scalar($value) || $value === null ? $value : '[complex]';
            }
        }

        return $out;
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max - 1).'…';
    }
}
