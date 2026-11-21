<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Table codes are 6 digits, short enough to type. Whoever opens a free table's code first
 * becomes its responsible (and can order on its bill), so codes must not be guessable by
 * trying them in a row. Only wrong codes count: a customer at a real table polls its page
 * every few seconds and must never be slowed down.
 *
 * Applies to routes with a {code} parameter. A wrong code is a 404 from the controller, or
 * reported with miss() where the controller answers it otherwise (a redirect with a toast).
 * Per account (the real limit) and per IP, looser: everyone on a venue's Wi-Fi shares one.
 */
final class ThrottleTableCodes
{
    public const MAX_MISSES_PER_USER = 10;

    public const MAX_MISSES_PER_IP = 50;

    public const DECAY_SECONDS = 15 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('code') === null) {
            return $next($request);
        }

        abort_if(self::blocked($request), 429);

        $response = $next($request);

        if ($response->getStatusCode() === Response::HTTP_NOT_FOUND) {
            self::miss($request);
        }

        return $response;
    }

    /** A code that matches no open table. */
    public static function miss(Request $request): void
    {
        foreach (self::limits($request) as $key => $max) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    private static function blocked(Request $request): bool
    {
        foreach (self::limits($request) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> limiter key => wrong codes allowed per window */
    private static function limits(Request $request): array
    {
        $limits = ['table-code:ip:'.$request->ip() => self::MAX_MISSES_PER_IP];

        if ($request->user() !== null) {
            $limits['table-code:user:'.$request->user()->id] = self::MAX_MISSES_PER_USER;
        }

        return $limits;
    }
}
