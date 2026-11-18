<?php

namespace App\Http;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Ssr\SsrState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders HTTP errors as a page of the app (resources/js/pages/error.tsx), inside the
 * main layout, so people keep the navigation instead of landing on a dead end.
 */
final class ErrorPage
{
    /** Statuses shown as a page, with the framework's (translated) title. */
    private const TITLES = [
        403 => 'Forbidden',
        404 => 'Not Found',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    public static function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        // JSON callers (API, post reactions) keep the JSON error.
        if ($request->is('api/*') || $request->wantsJson()) {
            return $response;
        }

        // A form left open too long: back to it with a message, instead of an error page.
        if ($status === 419) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'errors.page_expired']);

            return back();
        }

        // Keep Laravel's detailed error page while debugging.
        if (! isset(self::TITLES[$status]) || ($status >= 500 && config('app.debug'))) {
            return $response;
        }

        self::shareAppProps($request);

        // Inertia keeps the page being rendered in a per-request SsrState, and caches its HTML
        // once rendered. When the error comes from rendering another page (or an earlier render
        // in the same process), that state still holds the other page: start from a clean one,
        // or the error page would come out with the broken page's data.
        app()->forgetInstance(SsrState::class);

        return Inertia::render('error', [
            'status' => $status,
            'title' => __(self::TITLES[$status]),
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * Some errors happen before HandleInertiaRequests runs (a missing record in route
     * model binding, an exception in a middleware). Without its shared props, a
     * signed-in user would get the visitor's version of the page.
     */
    private static function shareAppProps(Request $request): void
    {
        try {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));
        } catch (\Throwable) {
            // No session at all (the error came before it started): show it as to a visitor.
            Inertia::share(['name' => config('app.name'), 'auth' => ['user' => null]]);
        }
    }
}
