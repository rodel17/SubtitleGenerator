<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sign-in happens in the SPA; the API itself has no sign-in page to redirect to.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : config('app.frontend_url').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Answer the API with JSON (e.g. 401) even when the client didn't send an Accept header.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
    })->create();
