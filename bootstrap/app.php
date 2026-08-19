<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12']);
        // Kobo devices carry no CSRF token. 'api/v3/*' is the reading-services (annotation) API,
        // which lives at the site root because the device resolves reading_services_host as an
        // origin; without this its POST/PATCH uploads are rejected with 419.
        $middleware->validateCsrfTokens(except: ['kobo/*', 'api/v3/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
