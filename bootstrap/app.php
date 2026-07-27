<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens([
            'auth/*',
            'public/*',
            'admin/*',
            'tenant/*',
            'master/*',
        ]);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenantCompany::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $exception): bool =>
            $request->expectsJson() || $request->is('auth/*', 'tenant/*', 'master/*', 'public/*', 'admin/*')
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Clean up pending orders every 15 minutes
        $schedule->command('orders:cleanup-pending')->everyFifteenMinutes();

        // Lembrete único de pagamento pendente aos 20min (§13.2)
        $schedule->command('orders:send-pending-payment-reminders')->everyFiveMinutes();
    })
    ->create();
