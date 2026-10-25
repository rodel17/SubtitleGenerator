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
        // On the server HTTPS ends at Caddy, which talks plain HTTP to nginx. Trusting its X-Forwarded-*
        // headers makes generated URLs (e.g. the Google callback) https. nginx is only reachable from
        // the host and the Docker network (docker-compose.yml), so nobody else can set these headers.
        $middleware->trustProxies(at: '*');

        // Sign-in happens in the SPA; the API itself has no sign-in page to redirect to.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : config('app.frontend_url').'/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Answer the API with JSON (e.g. 401) even when the client didn't send an Accept header.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());
    })->create();
