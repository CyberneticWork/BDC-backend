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
        ]);
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->api(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RequireApiAuth::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $minutes = max(1, (int) config('hikvision.poll_interval_minutes', 5));
        $schedule->command('hikvision:sync-attendance')->cron("*/{$minutes} * * * *");
        $schedule->command('attendance:missed-punch-alerts')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
