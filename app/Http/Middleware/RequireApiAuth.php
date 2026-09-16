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

        if ($request->is('api/cybernetic-admin/*')) {
            if ($this->cybernetic->checkRequest($request)) {
                return $next($request);
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        app(AuthenticateJwtOrSanctum::class)->authenticate($request);

        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }

    private function isPublic(Request $request): bool
    {
        return $request->is([
            'api/login',
            'api/send-otp',
            'api/login/otp',
            'api/public/*',
            'api/register',
            'api/cybernetic-admin/login',
            'api/hikvision/webhook/*',
            'api/hikvision/punches/*',
            'api/hikvision/cloud-base',
        ]);
    }
}
