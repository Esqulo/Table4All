<?php

namespace App\Support;

use App\Enums\Incentive;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Which customer incentives each restaurant has switched on, and since when. */
final class Incentives
{
    /**
     * When each of these restaurants switched the incentive on (UTC), keyed by
     * restaurant id; restaurants that have it off are left out.
     *
     * @param  list<int>  $restaurantIds
     * @return array<int, CarbonImmutable>
     */
    public static function enabledSince(Incentive $incentive, array $restaurantIds): array
    {
        if ($restaurantIds === []) {
            return [];
        }

        return DB::table('restaurant_incentives')
            ->where('incentive', $incentive->value)
            ->whereIn('restaurant_id', $restaurantIds)
            ->pluck('enabled_at', 'restaurant_id')
            ->mapWithKeys(fn (mixed $at, int|string $id) => [(int) $id => CarbonImmutable::parse((string) $at, 'UTC')])
            ->all();
    }

    public static function enabled(int $restaurantId, Incentive $incentive): bool
    {
        return DB::table('restaurant_incentives')
            ->where('restaurant_id', $restaurantId)
            ->where('incentive', $incentive->value)
            ->exists();
    }

    /**
     * Switching on starts the count from now; switching off forgets it, so turning
     * it back on later starts fresh.
     */
    public static function set(int $restaurantId, Incentive $incentive, bool $enabled): void
    {
        if (! $enabled) {
            DB::table('restaurant_incentives')
                ->where('restaurant_id', $restaurantId)
                ->where('incentive', $incentive->value)
                ->delete();

            return;
        }

        $now = now();

        // Already on: keep the original date (a repeated request changes nothing).
        DB::table('restaurant_incentives')->insertOrIgnore([
            'restaurant_id' => $restaurantId,
            'incentive' => $incentive->value,
            'enabled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
