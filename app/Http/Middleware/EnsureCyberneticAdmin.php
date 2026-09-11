<?php

namespace App\Http\Middleware;

use App\Services\CyberneticAdminAuth;
use Closure;
use Illuminate\Http\Request;

class EnsureCyberneticAdmin
{
    public function __construct(private CyberneticAdminAuth $auth)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!$this->auth->checkRequest($request)) {
            return response()->json([
                'message' => 'Cybernetic Admin login required.',
            ], 401);
        }

        return $next($request);
    }
}
