<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\RestaurantStory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The "Hoje tem!" strip of the feed: restaurants with live stories, the most recent first,
 * each with its stories in the order they were posted.
 *
 * Others see only ready stories of public profiles. The restaurant viewing the feed comes
 * first and also sees its own videos still converting or that failed to convert.
 */
final class StoryFeed
{
    /** Restaurants in the strip; the rest show up as these expire. */
    public const MAX_RESTAURANTS = 40;

    /** @return list<array<string, mixed>> */
    public static function forViewer(?User $viewer): array
    {
        $ownId = $viewer?->account_type === AccountType::RESTAURANT ? $viewer->id : null;

        $visible = fn ($query) => $query
            ->where('restaurant_stories.expires_at', '>', now())
            ->where(fn ($q) => $q
                ->where(fn ($others) => $others
                    ->where('restaurant_stories.status', RestaurantStory::READY)
                    ->whereIn('restaurant_stories.user_id', User::select('id')
                        ->where('account_type', AccountType::RESTAURANT)
                        ->where('profile_public', true)
                        ->whereNull('deactivated_at')))
                ->when($ownId !== null, fn ($q) => $q->orWhere('restaurant_stories.user_id', $ownId)));

        // Which restaurants, newest story first (one grouped query), then their stories (one query).
        $restaurantIds = DB::table('restaurant_stories')
            ->where($visible)
            ->groupBy('user_id')
            ->orderByRaw('MAX(id) DESC')
            ->limit(self::MAX_RESTAURANTS)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($restaurantIds === []) {
            return [];
        }

        $stories = RestaurantStory::query()
            ->where($visible)
            ->whereIn('user_id', $restaurantIds)
            ->orderBy('id')
            ->get(['id', 'user_id', 'media_type', 'path', 'poster_path', 'status', 'duration_ms', 'created_at', 'expires_at'])
            ->groupBy('user_id');

        $restaurants = User::whereIn('id', $restaurantIds)
            ->get(['id', 'name', 'handle', 'logo', 'account_type'])
            ->keyBy('id');

        // The viewer's own stories first, where its "add" button is.
        if ($ownId !== null && in_array($ownId, $restaurantIds, true)) {
            $restaurantIds = [$ownId, ...array_values(array_diff($restaurantIds, [$ownId]))];
        }

        $result = [];

        foreach ($restaurantIds as $id) {
            $restaurant = $restaurants->get($id);

            if ($restaurant === null || ! $stories->has($id)) {
                continue;
            }

            $result[] = [
                // Public fields only: the row is a user, which also carries email, phone and auth data.
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'handle' => (string) $restaurant->handle,
                    'logo_url' => $restaurant->logo_url,
                ],
                'is_owner' => $id === $ownId,
                'stories' => $stories->get($id)->map(fn (RestaurantStory $s): array => [
                    'id' => $s->id,
                    'media_type' => $s->media_type,
                    'status' => $s->status,
                    'url' => $s->url(),
                    'poster_url' => $s->posterUrl(),
                    'duration_ms' => $s->duration_ms,
                    'created_at' => $s->created_at?->toIso8601String(),
                    'expires_at' => $s->expires_at->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return $result;
    }
}
