<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\RestaurantOpeningHour;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/**
 * Finds restaurants with a public profile, for consumers: the search (by name
 * or by the profile's handle) and the list to browse before searching.
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
     * Every public restaurant, the most interesting first, for browsing before typing
     * a search. Page-based (the score isn't a cursor), without a COUNT query.
     *
     * The score (discoveryScore()) rewards what makes a profile worth opening: a good
     * rating (Bayesian, so a single 5-star review doesn't jump to the top), a "Hoje tem!"
     * story on air, a post in the last week, and a complete profile. Ties are shuffled
     * once a day, so the list doesn't always open with the same restaurants, but stay
     * stable within the day, so pages never repeat or skip a restaurant.
     *
     * @return Paginator<int, array<string, mixed>>
     */
    public static function discover(int $perPage, string $pageName = 'page'): Paginator
    {
        $now = now();
        $local = now(config('app.business_timezone'));

        $page = self::publicRestaurants()
            ->addSelect(['banner', 'address'])
            ->selectRaw(self::discoveryScore().' AS discovery_score', [$now, $now->subDays(7)])
            ->selectRaw('EXISTS ('.self::LIVE_STORY.') AS has_stories', [$now])
            ->orderByDesc('discovery_score')
            ->orderByRaw('md5(users.id::text || ?)', [$local->toDateString()])
            ->orderBy('id')
            ->simplePaginate($perPage, pageName: $pageName);

        $rows = new Collection($page->items());
        $hours = RestaurantOpeningHour::whereIn('user_id', $rows->modelKeys())
            ->get(['user_id', 'weekday', 'opens_at', 'closes_at'])
            ->groupBy('user_id');

        // present() keeps the order of $rows: index the cards by restaurant.
        $cards = array_combine($rows->modelKeys(), self::present($rows));

        /** @var Paginator<int, array<string, mixed>> $result */
        $result = $page->through(function (User $row) use ($cards, $hours, $local): array {
            $intervals = $hours->get($row->id);

            return $cards[$row->id] + [
                'banner_url' => $row->banner_url,
                'address' => $row->address,
                // Null when no hours were set: "closed" would be a guess.
                'is_open' => $intervals === null ? null : OpeningHours::isOpenAt(
                    $intervals->map(fn (RestaurantOpeningHour $h): array => [
                        'weekday' => $h->weekday,
                        'opens_at' => $h->opens_at,
                        'closes_at' => $h->closes_at,
                    ]),
                    $local,
                ),
                'has_stories' => (bool) $row->getAttribute('has_stories'),
            ];
        });

        return $result;
    }

    /** A live, ready "Hoje tem!" story of the restaurant in the outer query (binding: now). */
    private const LIVE_STORY = "SELECT 1 FROM restaurant_stories s WHERE s.user_id = users.id AND s.status = 'ready' AND s.expires_at > ?";

    /**
     * SQL expression ranking the discovery list (bindings: now, a week ago).
     *
     * @return literal-string
     */
    private static function discoveryScore(): string
    {
        return '('
            // Twice the Bayesian average (2 to 10): the rating weighs most. 5 imaginary reviews of 3.5
            // pull restaurants with few reviews toward the middle.
            .'2 * (COALESCE((SELECT SUM(rv.rating) FROM restaurant_reviews rv WHERE rv.restaurant_id = users.id), 0) + 17.5)'
            .' / (COALESCE((SELECT COUNT(*) FROM restaurant_reviews rv WHERE rv.restaurant_id = users.id), 0) + 5.0)'
            .' + CASE WHEN EXISTS ('.self::LIVE_STORY.') THEN 2 ELSE 0 END'
            .' + CASE WHEN EXISTS (SELECT 1 FROM restaurant_posts p WHERE p.user_id = users.id AND p.created_at > ?) THEN 1 ELSE 0 END'
            .' + CASE WHEN users.logo IS NOT NULL THEN 0.5 ELSE 0 END'
            .' + CASE WHEN users.banner IS NOT NULL THEN 0.5 ELSE 0 END'
            .' + CASE WHEN EXISTS (SELECT 1 FROM restaurant_photos ph WHERE ph.user_id = users.id) THEN 0.5 ELSE 0 END'
            .' + CASE WHEN EXISTS (SELECT 1 FROM restaurant_opening_hours oh WHERE oh.user_id = users.id) THEN 0.25 ELSE 0 END'
            .' + CASE WHEN users.address IS NOT NULL THEN 0.25 ELSE 0 END'
            .')';
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
