<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\Geo\Geocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds the restaurant's address on the map, so it can be listed by distance on the
 * discovery page. Dispatched when the address changes (User::booted()) and by
 * `restaurants:geocode` for addresses saved before.
 */
final class GeocodeRestaurant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Nominatim allows one request per second: a retry waits well past that. */
    public int $backoff = 30;

    public function __construct(public readonly int $restaurantId) {}

    public function handle(Geocoder $geocoder): void
    {
        $restaurant = User::find($this->restaurantId, ['id', 'address']);

        if ($restaurant === null || blank($restaurant->address)) {
            return;
        }

        $address = (string) $restaurant->address;
        $found = $geocoder->locate($address);

        if ($found === null) {
            return;
        }

        // Only if the address is still the one looked up: it may have changed meanwhile.
        User::whereKey($restaurant->id)
            ->where('address', $address)
            ->update(['latitude' => $found->latitude, 'longitude' => $found->longitude]);
    }
}
