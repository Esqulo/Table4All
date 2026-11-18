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

    /** Words of the term used; the box takes 60 characters, this only bounds the query. */
    private const MAX_WORDS = 8;

    /**
     * Full-text search (PostgreSQL, users.search_vector) over the name and @handle:
     * every word typed must start a word of either, in any order, so "grao prosa"
     * finds "Grão & Prosa Café" and "piz" finds "Pizzaria". Ignores case, accents
     * ("cafe" finds "Café") and symbols ("@", "&", "%" are not part of any word).
     * The exact @handle comes first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $term): array
    {
        $term = User::searchable(ltrim(trim($term), '@'));
        $query = self::prefixQuery($term);

        if (mb_strlen($term) < self::MIN_TERM || $query === null) {
            return [];
        }

        $rows = self::publicRestaurants()
            ->whereRaw("search_vector @@ to_tsquery('simple', ?)", [$query])
            ->orderByRaw('CASE WHEN handle = ? THEN 0 ELSE 1 END', [Str::slug($term)])
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return self::present($rows);
    }

    /**
     * "bar do ze" → "bar:* & do:* & ze:*". The words are only letters and digits
     * (the term is already ASCII), so nothing in them is tsquery syntax.
     */
    private static function prefixQuery(string $term): ?string
    {
        $words = preg_split('/[^a-z0-9]+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice(array_unique($words), 0, self::MAX_WORDS);

        return $words === [] ? null : implode(' & ', array_map(fn (string $w): string => $w.':*', $words));
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
}
