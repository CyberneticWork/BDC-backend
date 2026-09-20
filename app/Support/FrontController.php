<?php

namespace App\Support;

/**
 * Shared-host front controller: nginx often only runs PHP for URLs that end in .php,
 * so /index.php/api/... never reaches Laravel (HTML 404, no CORS headers).
 * Frontend can call /index.php?__lr=/api/... which always executes this file.
 */
class FrontController
{
    public static function apply(): void
    {
        $lr = self::logicalPathFromGlobals();
        if ($lr === null) {
            return;
        }

        $query = $_GET;
        unset($query['__lr']);
        $qs = http_build_query($query);

        $_GET = $query;
        $_SERVER['QUERY_STRING'] = $qs;
        $_SERVER['PATH_INFO'] = $lr;
        $_SERVER['REQUEST_URI'] = $lr.($qs !== '' ? '?'.$qs : '');
        $_SERVER['SCRIPT_NAME'] = self::scriptName();
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }

    private static function scriptName(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        if (preg_match('#^(.*?/index\.php)#i', $script, $m)) {
            return $m[1];
        }

        return '/index.php';
    }

    private static function logicalPathFromGlobals(): ?string
    {
        $lr = $_GET['__lr'] ?? '';
        if (is_string($lr)) {
            $normalized = self::safePath($lr);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (preg_match('#/index\.php(/.*)$#i', $uriPath, $m)) {
            $normalized = self::safePath((string) ($m[1] ?? ''));
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '' && str_starts_with($qs, '/')) {
            $first = explode('&', $qs, 2)[0];
            $normalized = self::safePath($first);
            if ($normalized !== null && str_starts_with($normalized, '/api')) {
                parse_str(explode('&', $qs, 2)[1] ?? '', $_GET);

                return $normalized;
            }
        }

        return null;
    }

    private static function safePath(string $path): ?string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '/' || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
