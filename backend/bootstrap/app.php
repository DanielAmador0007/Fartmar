<?php

use App\Http\ApiErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // /health y /ready sin middleware "web" (la sesión usa BD y la
            // liveness no debe depender de ella).
            Route::group([], base_path('routes/probes.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Formato uniforme { error: { code, message, details } } para errores
        // de negocio (409/422/403), validación, autenticación y 404.
        $exceptions->render(fn (Throwable $e, Request $request) => (new ApiErrorRenderer)($e, $request));
    })->create();
