<?php

namespace App\Support;

use App\Support\Geo\Coordinates;
use Illuminate\Http\Request;

/**
 * What the discovery page (/descobrir) was asked for, read from its address:
 *
 *   ?q=pizza          full-text search over the name and @handle
 *   ?lotacao=1-2      how full the venue is now: 1 calm … 3 packed ("bombando")
 *   ?preco=2-3        price level: 1 ($) … 3 ($$$)
 *   ?aberto=1         open now
 *   ?ordem=distancia  closest first (with ?lat=&lng= from the device, otherwise the IP)
 *   ?ordem=avaliacao  best rated first
 *
 * Everything is off by default. A range covering every level is the same as no filter,
 * and anything malformed is ignored rather than failing: the address can be edited by hand.
 */
final readonly class DiscoverFilters
{
    public const SORT_RELEVANCE = 'relevance';

    public const SORT_DISTANCE = 'distance';

    public const SORT_RATING = 'rating';

    public const MIN_LEVEL = 1;

    public const MAX_LEVEL = 3;

    /** The search box takes this many characters. */
    public const MAX_TERM = 60;

    /** The address's words for the sort (Portuguese, like the rest of the URLs). */
    private const SORT_PARAMS = ['distancia' => self::SORT_DISTANCE, 'avaliacao' => self::SORT_RATING];

    /**
     * @param  array{int, int}|null  $crowd  null when off
     * @param  array{int, int}|null  $price  null when off
     * @param  'relevance'|'distance'|'rating'  $sort
     */
    public function __construct(
        public string $term = '',
        public ?array $crowd = null,
        public ?array $price = null,
        public bool $openNow = false,
        public string $sort = self::SORT_RELEVANCE,
        public ?Coordinates $origin = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $sort = self::SORT_PARAMS[self::text($request, 'ordem')] ?? self::SORT_RELEVANCE;

        return new self(
            term: mb_substr(trim(self::text($request, 'q')), 0, self::MAX_TERM),
            crowd: self::range($request->query('lotacao')),
            price: self::range($request->query('preco')),
            openNow: $request->boolean('aberto'),
            sort: $sort,
            origin: $sort === self::SORT_DISTANCE
                ? Coordinates::tryFrom($request->query('lat'), $request->query('lng'))?->rounded()
                : null,
        );
    }

    public function withOrigin(?Coordinates $origin): self
    {
        return new self($this->term, $this->crowd, $this->price, $this->openNow, $this->sort, $origin);
    }

    /**
     * What the page shows in its controls; ranges that are off span every level.
     *
     * @return array{q: string, crowd: array{int, int}, price: array{int, int}, open: bool, sort: string}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->term,
            'crowd' => $this->crowd ?? [self::MIN_LEVEL, self::MAX_LEVEL],
            'price' => $this->price ?? [self::MIN_LEVEL, self::MAX_LEVEL],
            'open' => $this->openNow,
            'sort' => $this->sort,
        ];
    }

    /** A query parameter that should be text; '' when missing or not text (?q[]=x). */
    private static function text(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /**
     * "1-2" → [1, 2]. Null (no filter) for anything else, and for the whole range.
     *
     * @return array{int, int}|null
     */
    private static function range(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^([1-3])-([1-3])$/', $value, $m)) {
            return null;
        }

        $range = [min((int) $m[1], (int) $m[2]), max((int) $m[1], (int) $m[2])];

        return $range === [self::MIN_LEVEL, self::MAX_LEVEL] ? null : $range;
    }
}
