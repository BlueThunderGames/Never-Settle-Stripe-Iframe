<?php

use App\Http\Middleware\AllowIframeEmbedding;
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
        // Prepended so error pages (404s from route binding, etc.) are covered too.
        $middleware->web(prepend: [AllowIframeEmbedding::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
