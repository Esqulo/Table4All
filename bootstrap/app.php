<?php

use App\Http\ErrorPage;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectAdminTwoFactor;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Written by the frontend in plain text, so they can't be encrypted.
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', SetLocale::COOKIE]);

        // Signed by Stripe instead (StripeWebhookController).
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);

        // Global, not just "web": a URL that matches no route still renders its 404 page
        // in the user's language. The cookie is not encrypted, so it is readable this early.
        $middleware->append(SetLocale::class);

        // The admin area has its own sign-in screen (two steps: password, then authenticator code).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            TrackLastSeen::class,
            ProtectAdminTwoFactor::class,
            // Only the first assets (entry CSS/JS): the built app preloads ~35 chunks, and a
            // header that long (4.5 KB) overflows nginx's FastCGI buffer, which turns every full
            // page into a 502. The HTML still carries a modulepreload tag for each chunk.
            AddLinkHeadersForPreloadedAssets::using(10),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JSON for API routes and for the few requests that ask for it explicitly
        // (post reactions). Inertia visits don't send "Accept: application/json".
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->wantsJson(),
        );

        // Error pages render inside the app (with its navigation), not as a bare page.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request) => ErrorPage::respond($response, $request),
        );
    })->create();
