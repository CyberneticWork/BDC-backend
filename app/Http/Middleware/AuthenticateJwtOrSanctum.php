<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateJwtOrSanctum
{
    public function __construct(private JwtTokenService $jwt)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            $this->authenticate($request);
        }

        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }

    public function authenticate(Request $request): void
    {
        $bearer = $request->bearerToken();
        if (!$bearer) {
            return;
        }

        $user = null;
        if ($this->jwt->isJwt($bearer)) {
            $user = $this->jwt->userFromBearer($bearer);
        } else {
            $accessToken = PersonalAccessToken::findToken($bearer);
            if ($accessToken && $this->sanctumTokenExpired($accessToken)) {
                return;
            }
            $tokenable = $accessToken?->tokenable;
            $user = $tokenable instanceof User ? $tokenable : null;
        }

        if (!$user) {
            return;
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
    }

    private function sanctumTokenExpired(PersonalAccessToken $token): bool
    {
        if ($token->expires_at && $token->expires_at->isPast()) {
            return true;
        }
        $minutes = (int) config('sanctum.expiration');

        return $minutes > 0 && $token->created_at && $token->created_at->lte(now()->subMinutes($minutes));
    }
}
