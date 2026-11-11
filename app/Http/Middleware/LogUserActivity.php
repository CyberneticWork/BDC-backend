<?php

namespace App\Http\Middleware;

use App\Services\UserActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            UserActivityLogger::fromHttp($request, (int) $response->getStatusCode());
        } catch (\Throwable $e) {
            // never break the response
        }

        return $response;
    }
}
