<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds restaurants with a public profile, for consumers: the search (by name
 * or by the profile's handle) and the "recently active" strip of the feed.
 */
final class RestaurantDirectory
{
    /** Shorter terms match too much to be useful. */
    public const MIN_TERM = 2;

    public const MAX_RESULTS = 20;

    /**
     * Restaurants whose name or @handle contains $term, exact @handle first.
     * Ignores case and accents ("cafe" finds "Café", "ze" finds "Zé"), and
     * "bar do ze" also finds the handle "bar-do-ze".
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $term): array
    {
        $term = User::searchable(ltrim(trim($term), '@'));

        if (mb_strlen($term) < self::MIN_TERM) {
            return [];
        }

        $like = '%'.self::escapeLike($term).'%';
        // "bar do ze" → "bar-do-ze", to reach handles. Only for plain words:
        // slugging "50%" would drop the "%" and turn it into a much looser "50".
        $slug = preg_match('/^[\p{L}\p{N}\s-]+$/u', $term) ? Str::slug($term) : '';

        $rows = self::publicRestaurants()
            ->where(function (Builder $q) use ($like, $slug): void {
                // search_name is the name already lowercased and without accents (see User::booted()).
                $q->whereRaw("search_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("handle LIKE ? ESCAPE '!'", [$like]);

                if (mb_strlen($slug) >= self::MIN_TERM) {
                    $q->orWhereRaw("handle LIKE ? ESCAPE '!'", ['%'.self::escapeLike($slug).'%']);
                }
            })
            ->orderByRaw('CASE WHEN handle = ? THEN 0 ELSE 1 END', [$slug !== '' ? $slug : $term])
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return self::present($rows);
    }

    /**
     * Restaurants that posted most recently (the ones without posts last), to browse from the feed.
     *
     * @return list<array<string, mixed>>
     */
    public static function recentlyActive(int $limit = self::MAX_RESULTS): array
    {
        $rows = self::publicRestaurants()
            ->orderByDesc(DB::raw('COALESCE((SELECT MAX(p.id) FROM restaurant_posts p WHERE p.user_id = users.id), 0)'))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return self::present($rows);
    }

    /** @return Builder<User> */
    private static function publicRestaurants(): Builder
    {
        return User::query()
            ->where('account_type', AccountType::RESTAURANT)
            ->where('profile_public', true)
            ->whereNotNull('handle')
            ->select(['id', 'name', 'handle', 'logo', 'account_type'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews');
    }

    /**
     * Public fields only: the rows are users, which also carry email, phone and auth data.
     *
     * @param  iterable<User>  $rows
     * @return list<array<string, mixed>>
     */
    private static function present(iterable $rows): array
    {
        $result = [];

        foreach ($rows as $r) {
            $count = (int) $r->getAttribute('reviews_count');
            $result[] = [
                'id' => $r->id,
                'name' => $r->name,
                'handle' => (string) $r->handle,
                'logo_url' => $r->logo_url,
                'rating' => [
                    'average' => $count > 0 ? round((float) $r->getAttribute('reviews_avg_rating'), 1) : 0.0,
                    'count' => $count,
                ],
            ];
        }

        return $result;
    }

    /** "50%_off" must match literally, not as LIKE wildcards. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
