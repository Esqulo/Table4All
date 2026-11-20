<?php

namespace App\Support\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Roughly where a visitor is, from their IP address: what "closest first" uses when
 * the browser can't or won't share the device's location. City-level at best.
 *
 * Behind Cloudflare with its visitor location headers on, the request already says;
 * otherwise an outside service is asked (services.ip_location: a URL with "{ip}",
 * answering JSON with "latitude" and "longitude"; empty turns it off, as in the tests).
 * Answers are cached per address, so a visitor scrolling the list asks once a day.
 */
final class IpLocator
{
    private const CACHE_HOURS = 24;

    /** Failures are retried sooner: the service may just have been down. */
    private const FAILURE_CACHE_MINUTES = 30;

    public function locate(Request $request): ?Coordinates
    {
        $fromEdge = Coordinates::tryFrom($request->header('CF-IPLatitude'), $request->header('CF-IPLongitude'));

        if ($fromEdge !== null) {
            return $fromEdge->rounded();
        }

        $ip = (string) $request->ip();
        $url = (string) config('services.ip_location.url');

        // Private and local addresses (the LAN, Docker) are nowhere on the map.
        if ($url === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $key = 'ip-location:'.hash('sha256', $ip);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return Coordinates::tryFrom($cached['lat'] ?? null, $cached['lng'] ?? null);
        }

        $found = $this->ask(str_replace('{ip}', rawurlencode($ip), $url));

        if ($found === null) {
            Cache::put($key, [], now()->addMinutes(self::FAILURE_CACHE_MINUTES));
        } else {
            Cache::put($key, ['lat' => $found->latitude, 'lng' => $found->longitude], now()->addHours(self::CACHE_HOURS));
        }

        return $found;
    }

    private function ask(string $url): ?Coordinates
    {
        try {
            // Short: the visitor is waiting for the list.
            $response = Http::timeout(3)->acceptJson()->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || $response->json('success') === false) {
            return null;
        }

        return Coordinates::tryFrom($response->json('latitude'), $response->json('longitude'))?->rounded();
    }
}
