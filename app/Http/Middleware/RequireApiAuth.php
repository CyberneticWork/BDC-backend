<?php

namespace App\Http\Middleware;

use App\Services\CyberneticAdminAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiAuth
{
    public function __construct(private CyberneticAdminAuth $cybernetic)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') || $this->isPublic($request)) {
            return $next($request);
        }

        if ($this->cybernetic->checkRequest($request) || ($request->bearerToken() && $request->user('sanctum'))) {
            return $next($request);
        }

        return response()->json(['message' => 'Authentication required.'], 401);
    }

    private function isPublic(Request $request): bool
    {
        $path = preg_replace('#^api/#', '', trim($request->path(), '/'));
        $method = strtoupper($request->method());

        $exact = [
            'POST login',
            'POST send-otp',
            'POST login/otp',
            'POST cybernetic-admin/login',
            'POST register',
            'GET hikvision/cloud-base',
            'GET apiData/companies',
        ];
        if (in_array($method.' '.$path, $exact, true)) {
            return true;
        }

        if ($method === 'GET' && str_starts_with($path, 'public/')) {
            return true;
        }
        if ($method === 'POST' && (str_starts_with($path, 'hikvision/webhook/') || str_starts_with($path, 'hikvision/punches/'))) {
            return true;
        }

        return false;
    }
}
