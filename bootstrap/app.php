<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Midtrans mengirim notifikasi tanpa token CSRF; keasliannya dicek lewat signature_key.
        $middleware->validateCsrfTokens(except: ['payments/midtrans/notification']);

        // Akun hanya boleh dipakai di domain portalnya (LMS vs tes online).
        $middleware->web(append: [\App\Http\Middleware\EnsurePortal::class]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
