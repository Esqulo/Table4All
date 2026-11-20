<?php

namespace App\Support\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds a written address on the map, through a Nominatim-compatible service
 * (OpenStreetMap's by default: services.geocoding). Used for the restaurants'
 * addresses, on the queue; an empty URL turns it off (as in the tests).
 */
final class Geocoder
{
    public function enabled(): bool
    {
        return filled(config('services.geocoding.url'));
    }

    public function locate(string $address): ?Coordinates
    {
        $address = trim($address);

        if (! $this->enabled() || $address === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                // Nominatim's usage policy asks every application to identify itself.
                ->withUserAgent(config('app.name').' ('.config('app.url').')')
                ->get((string) config('services.geocoding.url'), array_filter([
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'countrycodes' => config('services.geocoding.country'),
                ]));
        } catch (ConnectionException $e) {
            Log::warning('Geocoding failed: '.$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Geocoding failed with HTTP '.$response->status());

            return null;
        }

        $first = $response->json('0');

        return is_array($first) ? Coordinates::tryFrom($first['lat'] ?? null, $first['lon'] ?? null) : null;
    }
}
