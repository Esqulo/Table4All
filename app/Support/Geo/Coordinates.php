<?php

namespace App\Support\Geo;

/** A point on the map, in decimal degrees. */
final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /** Null when either value is missing or off the globe. */
    public static function tryFrom(mixed $latitude, mixed $longitude): ?self
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return new self($lat, $lng);
    }

    /**
     * Rounded to 3 decimals (about 100 m): close enough to sort by distance,
     * vague enough not to pin the viewer's home, and kinder to caches.
     */
    public function rounded(): self
    {
        return new self(round($this->latitude, 3), round($this->longitude, 3));
    }
}
