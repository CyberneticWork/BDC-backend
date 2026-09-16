<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'cybernetic' => \App\Http\Middleware\EnsureCyberneticAdmin::class,
            'auth.token' => \App\Http\Middleware\AuthenticateJwtOrSanctum::class,
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\SecureApiResponse::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RequireApiAuth::class,
        ]);
        $middleware->throttleApi();
    })
    ->withSchedule(function (Schedule $schedule): void {
        $minutes = max(1, (int) config('hikvision.poll_interval_minutes', 5));
        $schedule->command('hikvision:sync-attendance')->cron("*/{$minutes} * * * *");
        $schedule->command('attendance:missed-punch-alerts')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 500;
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            if (!config('app.debug')) {
                $message = match (true) {
                    $status === 401 => 'Unauthenticated.',
                    $status === 403 => 'Forbidden.',
                    $status === 404 => 'Not found.',
                    default => 'Request failed.',
                };
                if ($status >= 500) {
                    \Illuminate\Support\Facades\Log::error($e);
                }
            } else {
                $message = $e->getMessage() ?: 'Request failed.';
            }

            return response()->json(['message' => $message], $status);
        });
    })->create();
