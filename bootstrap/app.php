<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Written by the frontend in plain text, so they can't be encrypted.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', SetLocale::COOKIE]);

        // Global, not just "web": a URL that matches no route still renders its 404 page
        // in the user's language. The cookie is not encrypted, so it is readable this early.
        $middleware->append(SetLocale::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
