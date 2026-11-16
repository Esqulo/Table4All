<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Guards write endpoints against duplicate submissions.
 *
 * On a slow or flaky connection a request can reach the server while the
 * client gives up waiting, and the user taps the button again. The client
 * sends one `request_key` per logical action (kept until the action
 * succeeds), and the server processes each key only once.
 *
 * Cache::add() is atomic on every store we use (database, redis, file with
 * locks), so two concurrent requests with the same key cannot both pass.
 */
final class Idempotency
{
    private const TTL_SECONDS = 600;

    /**
     * Runs $action unless this scope already processed the request's key.
     * Returns false when the request was a duplicate (nothing was done).
     * If $action throws, the key is released so the user can retry.
     * Requests without a key (older clients) are always processed.
     *
     * Call it after validation so a rejected request does not burn the key.
     *
     * @param  callable(): mixed  $action
     */
    public static function once(Request $request, string $scope, callable $action): bool
    {
        $key = $request->input('request_key');

        if (! is_string($key) || $key === '' || strlen($key) > 64) {
            $action();

            return true;
        }

        $cacheKey = 'idempotency:'.$scope.':'.$key;

        if (! Cache::add($cacheKey, true, self::TTL_SECONDS)) {
            return false;
        }

        try {
            $action();
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);

            throw $e;
        }

        return true;
    }
}
