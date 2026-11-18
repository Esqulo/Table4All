<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uses the language the user picked in the interface for everything the
 * server says: validation and login errors, flash messages, emails sent
 * during the request and error pages.
 *
 * The frontend owns the choice (resources/js/i18n.ts) and mirrors it into the
 * `locale` cookie. Without the cookie (first visit) the configured default
 * applies, which matches the frontend default (pt_BR).
 */
class SetLocale
{
    public const COOKIE = 'locale';

    /** Locales with translations on both sides (lang/ and resources/js/locales/). */
    public const SUPPORTED = ['pt_BR', 'en'];

    /** Without a valid cookie (first visit): the frontend's default too. Not config('app.locale'), which setLocale() overwrites. */
    public const DEFAULT = 'pt_BR';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie(self::COOKIE);

        // Always set, falling back to the configured default: a long-lived process
        // (queue worker, Octane, the test suite) must not keep the previous request's language.
        App::setLocale(is_string($locale) && in_array($locale, self::SUPPORTED, true)
            ? $locale
            : self::DEFAULT);

        return $next($request);
    }
}
