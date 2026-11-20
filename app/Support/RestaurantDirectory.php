<?php

namespace App\Support;

use App\Enums\AccountType;
use App\Models\RestaurantOpeningHour;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Str;

/**
 * Finds restaurants with a public profile, for consumers: the discovery page
 * (/descobrir), with its search, filters and sort orders.
 */
final class RestaurantDirectory
{
    /** Shorter terms match too much to be useful. */
    public const MIN_TERM = 2;

    /** Words of the term used; the box takes 60 characters, this only bounds the query. */
    private const MAX_WORDS = 8;

    /**
     * Tables left open longer than this are taken as forgotten, not as people still
     * at the table: they don't count towards how full the venue is.
     */
    public const OPEN_TABLE_HOURS = 12;

    /**
     * One page of public restaurants matching $filters, in the order asked for.
     * Page-based (the score isn't a cursor), without a COUNT query.
     *
     * - Search: every word typed must start a word of the name or @handle (PostgreSQL
     *   full text, users.search_vector), ignoring case, accents and symbols; the exact
     *
     *   @handle comes first.
     * - How full ("lotação"): open tables over the tables the venue says it has
     *   (users.table_capacity), in thirds: 1 calm, 2, 3 packed. Unknown without a capacity,
     *   so those restaurants drop out when the filter is on.
     * - Price: users.price_level (1 to 3); unset ones drop out when the filter is on.
     * - Open now: the opening hours, in the business timezone, overnight shifts included.
     * - Sort: by default the most interesting first (discoveryScore()); closest first when
     *   the viewer's position is known (restaurants not found on the map go last); or best
     *   rated (Bayesian, so a single 5-star review doesn't jump to the top).
     *
     * Ties are shuffled once a day, so the list doesn't always open with the same
     * restaurants, but stay stable within the day, so pages never repeat or skip one.
     *
     * @return Paginator<int, array<string, mixed>>
     */
    public static function discover(DiscoverFilters $filters, int $perPage, string $pageName = 'page'): Paginator
    {
        $now = now();
        $local = now(config('app.business_timezone'));
        $openSince = $now->copy()->subHours(self::OPEN_TABLE_HOURS);

        $query = self::publicRestaurants()
            ->addSelect(['banner', 'address', 'price_level'])
            ->selectRaw(self::discoveryScore().' AS discovery_score', [$now, $now->copy()->subDays(7)])
            ->selectRaw('EXISTS ('.self::LIVE_STORY.') AS has_stories', [$now])
            ->selectRaw(self::CROWD_LEVEL.' AS crowd_level', [$openSince]);

        $exactHandle = null;

        if ($filters->term !== '') {
            $term = User::searchable(ltrim($filters->term, '@'));
            $tsquery = self::prefixQuery($term);

            // Too short, or only symbols: nothing to search for, so the list stays unfiltered.
            if (mb_strlen($term) >= self::MIN_TERM && $tsquery !== null) {
                $query->whereRaw("search_vector @@ to_tsquery('simple', ?)", [$tsquery]);
                $exactHandle = Str::slug($term);
            }
        }

        if ($filters->crowd !== null) {
            $query->whereRaw(self::CROWD_LEVEL.' BETWEEN ? AND ?', [$openSince, ...$filters->crowd]);
        }

        if ($filters->price !== null) {
            $query->whereBetween('price_level', $filters->price);
        }

        if ($filters->openNow) {
            self::whereOpenAt($query, $local);
        }

        if ($filters->origin !== null) {
            $query->selectRaw(self::DISTANCE_KM.' AS distance_km', [
                $filters->origin->latitude,
                $filters->origin->latitude,
                $filters->origin->longitude,
            ]);
        }

        if ($exactHandle !== null) {
            $query->orderByRaw('CASE WHEN handle = ? THEN 0 ELSE 1 END', [$exactHandle]);
        }

        if ($filters->sort === DiscoverFilters::SORT_DISTANCE && $filters->origin !== null) {
            $query->orderByRaw('distance_km ASC NULLS LAST');
        } elseif ($filters->sort === DiscoverFilters::SORT_RATING) {
            $query->selectRaw(self::BAYESIAN_RATING.' AS rating_score')
                ->orderByDesc('rating_score')
                ->orderByDesc('reviews_count');
        }

        $page = $query
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
            $distance = $row->getAttribute('distance_km');
            $crowd = $row->getAttribute('crowd_level');

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
                'price_level' => $row->price_level,
                'crowd_level' => $crowd === null ? null : (int) $crowd,
                'distance_km' => $distance === null ? null : round((float) $distance, 1),
            ];
        });

        return $result;
    }

    /** A live, ready "Hoje tem!" story of the restaurant in the outer query (binding: now). */
    private const LIVE_STORY = "SELECT 1 FROM restaurant_stories s WHERE s.user_id = users.id AND s.status = 'ready' AND s.expires_at > ?";

    /**
     * How full the venue is now, 1 to 3, or null without a table count (binding: the oldest
     * open table that still counts). Thirds of the capacity: under a third is calm (1), from
     * two thirds up is packed (3). Served by restaurant_tables (user_id, closed_at).
     */
    private const CROWD_LEVEL = '(CASE WHEN COALESCE(users.table_capacity, 0) = 0 THEN NULL ELSE LEAST(3, 1 + FLOOR(3.0 * ('
        .'SELECT COUNT(*) FROM restaurant_tables t WHERE t.user_id = users.id AND t.closed_at IS NULL'
        .' AND t.deleted_at IS NULL AND t.created_at > ?'
        .') / users.table_capacity))::int END)';

    /**
     * Great-circle distance in km from the viewer (bindings: latitude, latitude, longitude);
     * null for restaurants not found on the map. LEAST() guards ASIN against rounding above 1;
     * it skips NULLs in PostgreSQL (it would turn a missing position into 1), hence the CASE.
     */
    private const DISTANCE_KM = '(CASE WHEN users.latitude IS NULL OR users.longitude IS NULL THEN NULL'
        .' ELSE 6371 * 2 * ASIN(LEAST(1, SQRT('
        .'POWER(SIN(RADIANS(users.latitude - ?) / 2), 2)'
        .' + COS(RADIANS(?)) * COS(RADIANS(users.latitude)) * POWER(SIN(RADIANS(users.longitude - ?) / 2), 2)'
        .'))) END)';

    /** Bayesian average (1 to 5): 5 imaginary reviews of 3.5 pull restaurants with few reviews toward the middle. */
    private const BAYESIAN_RATING = '((COALESCE((SELECT SUM(rv.rating) FROM restaurant_reviews rv WHERE rv.restaurant_id = users.id), 0) + 17.5)'
        .' / (COALESCE((SELECT COUNT(*) FROM restaurant_reviews rv WHERE rv.restaurant_id = users.id), 0) + 5.0))';

    /**
     * SQL expression ranking the discovery list (bindings: now, a week ago).
     *
     * @return literal-string
     */
    private static function discoveryScore(): string
    {
        return '('
            // Twice the Bayesian average (2 to 10): the rating weighs most.
            .'2 * '.self::BAYESIAN_RATING
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
     * Restaurants open at $now (already in the business timezone): a shift that started
     * today and is still running, or yesterday's overnight shift that hasn't ended yet
     * (closing at or before the opening time means after midnight). Same rules as
     * OpeningHours::isOpenAt(), in SQL; served by restaurant_opening_hours (user_id, weekday).
     *
     * @param  Builder<User>  $query
     */
    private static function whereOpenAt(Builder $query, CarbonInterface $now): void
    {
        $today = $now->dayOfWeek;
        $yesterday = ($today + 6) % 7;
        $time = $now->format('H:i:s');

        $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('restaurant_opening_hours as oh')
            ->whereColumn('oh.user_id', 'users.id')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q
                    ->where('oh.weekday', $today)
                    ->where('oh.opens_at', '<=', $time)
                    ->where(fn ($q) => $q->whereColumn('oh.closes_at', '<=', 'oh.opens_at')->orWhere('oh.closes_at', '>', $time)))
                ->orWhere(fn ($q) => $q
                    ->where('oh.weekday', $yesterday)
                    ->whereColumn('oh.closes_at', '<=', 'oh.opens_at')
                    ->where('oh.closes_at', '>', $time))));
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
