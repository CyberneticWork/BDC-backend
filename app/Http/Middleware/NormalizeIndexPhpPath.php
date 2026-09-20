<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;

class NormalizeIndexPhpPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        if (!str_starts_with($path, 'index.php/')) {
            return $next($request);
        }

        $logical = '/'.substr($path, strlen('index.php'));
        $qs = $request->getQueryString();
        $request->server->set('PATH_INFO', $logical);
        $request->server->set('REQUEST_URI', $logical.($qs ? '?'.$qs : ''));
        $this->resetPathCache($request);

        return $next($request);
    }

    private function resetPathCache(Request $request): void
    {
        foreach (['pathInfo', 'requestUri', 'baseUrl', 'basePath'] as $prop) {
            try {
                $ref = new ReflectionProperty(SymfonyRequest::class, $prop);
                $ref->setAccessible(true);
                $ref->setValue($request, null);
            } catch (\Throwable) {
            }
        }
    }
}
