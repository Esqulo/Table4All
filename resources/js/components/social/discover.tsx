import { InfiniteScroll, Link, router, useRemember } from '@inertiajs/react';
import {
    Loader2,
    MapPin,
    Search,
    SlidersHorizontal,
    Star,
    UserRound,
    Store,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { SEARCH_HASH } from '@/lib/social';
import { cn } from '@/lib/utils';
import restaurantProfile from '@/routes/restaurant-profile';
import type {
    CrowdLevel,
    DiscoverFilters,
    DiscoverRestaurant,
    DiscoverSort,
    PriceLevel,
} from '@/types';

/** Waits for a pause in typing before asking the server (slow mobile data). */
const DEBOUNCE_MS = 300;
const MIN_LEVEL = 1;
const MAX_LEVEL = 3;
const FULL_RANGE: [number, number] = [MIN_LEVEL, MAX_LEVEL];

/** The #buscar focus happens once per page load (see DiscoverBrowser). */
let hashFocusDone = false;

type Coords = { lat: number; lng: number };

/** Where "closest first" measured from, as the server says. */
export type DiscoverOrigin = 'device' | 'ip' | null;

function isFullRange(range: [number, number]): boolean {
    return range[0] === MIN_LEVEL && range[1] === MAX_LEVEL;
}

function countActive(f: DiscoverFilters): number {
    return (
        Number(!isFullRange(f.crowd)) +
        Number(!isFullRange(f.price)) +
        Number(f.open)
    );
}

/** The address DiscoverFilters (server) reads: only what is on, so the defaults keep a clean URL. */
function toQuery(f: DiscoverFilters, coords: Coords | null) {
    const query: Record<string, string | number> = {};
    const q = f.q.trim();

    if (q) {
        query.q = q;
    }

    if (!isFullRange(f.crowd)) {
        query.lotacao = `${f.crowd[0]}-${f.crowd[1]}`;
    }

    if (!isFullRange(f.price)) {
        query.preco = `${f.price[0]}-${f.price[1]}`;
    }

    if (f.open) {
        query.aberto = 1;
    }

    if (f.sort === 'distance') {
        query.ordem = 'distancia';

        if (coords) {
            query.lat = coords.lat;
            query.lng = coords.lng;
        }
    } else if (f.sort === 'rating') {
        query.ordem = 'avaliacao';
    }

    return query;
}

/** The device position already in the address (sorting by distance), so other changes keep it. */
function coordsInUrl(): Coords | null {
    const params = new URLSearchParams(window.location.search);
    const lat = Number(params.get('lat'));
    const lng = Number(params.get('lng'));

    return params.has('lat') &&
        params.has('lng') &&
        Number.isFinite(lat) &&
        Number.isFinite(lng)
        ? { lat, lng }
        : null;
}

/**
 * Asks the browser for the device's position (the permission prompt, the first time).
 * Null when refused, unavailable or on plain HTTP (the API needs a secure context):
 * the server then places the visitor by IP.
 */
function locateDevice(): Promise<Coords | null> {
    return new Promise((resolve) => {
        if (!window.isSecureContext || !('geolocation' in navigator)) {
            resolve(null);

            return;
        }

        navigator.geolocation.getCurrentPosition(
            // ~100 m is plenty to sort by distance, and keeps the home address out of the URL.
            (p) =>
                resolve({
                    lat: Math.round(p.coords.latitude * 1000) / 1000,
                    lng: Math.round(p.coords.longitude * 1000) / 1000,
                }),
            () => resolve(null),
            { enableHighAccuracy: false, timeout: 10_000, maximumAge: 600_000 },
        );
    });
}

/**
 * The discovery page's search box, filters, sort order and grid. Every change reloads
 * only the grid (and what the server read back), starting it over; the choices live in
 * the address (replaced, not pushed), so back leaves the page and a shared link opens
 * the same list.
 */
export function DiscoverBrowser({
    filters,
    origin,
    restaurants,
}: {
    filters: DiscoverFilters;
    origin: DiscoverOrigin;
    restaurants: DiscoverRestaurant[];
}) {
    const { t } = useTranslation();
    const [current, setCurrent] = useState<DiscoverFilters>(filters);
    const [loading, setLoading] = useState(false);
    // While dragging, the sliders move here; the list reloads when they're let go.
    const [crowd, setCrowd] = useState<[number, number]>(filters.crowd);
    const [price, setPrice] = useState<[number, number]>(filters.price);
    const [searching, setSearching] = useState(false);
    const [locating, setLocating] = useState(false);
    const [panelOpen, setPanelOpen] = useRemember(
        countActive(filters) > 0,
        'discover-filters-open',
    );
    // The term the grid is for.
    const lastSent = useRef(filters.q.trim());
    const inputRef = useRef<HTMLInputElement>(null);
    const active = countActive({ ...current, crowd, price });

    // Arriving from the nav's search button (#buscar): focus the box, after mounting
    // (the server never sees the hash) and once per page load.
    useEffect(() => {
        if (hashFocusDone || window.location.hash !== SEARCH_HASH) {
            return;
        }

        hashFocusDone = true;
        inputRef.current?.focus();
    }, []);

    const visit = (next: DiscoverFilters, coords: Coords | null = null) => {
        router.get(
            DiscoverController.index.url(),
            toQuery(
                next,
                next.sort === 'distance' ? (coords ?? coordsInUrl()) : null,
            ),
            {
                only: ['discover', 'filters', 'origin'],
                reset: ['discover'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => {
                    setLoading(false);
                    setSearching(false);
                },
            },
        );
    };

    const apply = (patch: Partial<DiscoverFilters>) => {
        const next = { ...current, crowd, price, ...patch };
        setCurrent(next);
        visit(next);
    };

    useEffect(() => {
        const q = current.q.trim();

        // Only when the term really changed: mounting, React running effects twice in
        // development, or coming back to the page must not reload the list.
        if (q === lastSent.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastSent.current = q;
            setSearching(true);
            visit({ ...current, crowd, price });
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
        // Only the term schedules a search; the other controls reload on their own.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [current.q]);

    const sortBy = async (sort: DiscoverSort) => {
        if (sort === current.sort) {
            return;
        }

        const next: DiscoverFilters = { ...current, crowd, price, sort };
        setCurrent(next);

        if (next.sort !== 'distance') {
            visit(next);

            return;
        }

        setLocating(true);
        const coords = await locateDevice();
        setLocating(false);
        // Without the device's position the server falls back to the IP.
        visit(next, coords);
    };

    const clear = () => {
        setCrowd(FULL_RANGE);
        setPrice(FULL_RANGE);
        apply({ crowd: FULL_RANGE, price: FULL_RANGE, open: false });
    };

    /** The empty grid's way out: no filters and no search term. */
    const clearAll = () => {
        lastSent.current = '';
        setCrowd(FULL_RANGE);
        setPrice(FULL_RANGE);
        apply({ q: '', crowd: FULL_RANGE, price: FULL_RANGE, open: false });
    };

    return (
        <div className="space-y-3">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    ref={inputRef}
                    type="search"
                    value={current.q}
                    onChange={(e) =>
                        setCurrent({ ...current, q: e.target.value })
                    }
                    placeholder={t('social.search_placeholder')}
                    aria-label={t('social.search_placeholder')}
                    autoComplete="off"
                    enterKeyHint="search"
                    maxLength={60}
                    className="h-11 w-full rounded-xl border bg-card pr-10 pl-9 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm [&::-webkit-search-cancel-button]:hidden"
                />
                {searching ? (
                    <Loader2 className="absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                ) : (
                    current.q !== '' && (
                        <button
                            type="button"
                            onClick={() => setCurrent({ ...current, q: '' })}
                            aria-label={t('social.search_clear')}
                            className="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    )
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant={panelOpen ? 'secondary' : 'outline'}
                    size="sm"
                    onClick={() => setPanelOpen(!panelOpen)}
                    aria-expanded={panelOpen}
                    aria-controls="discover-filters"
                    className="rounded-full"
                >
                    <SlidersHorizontal className="h-4 w-4" />
                    {t('social.filters_button')}
                    {active > 0 && (
                        <span className="flex size-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground tabular-nums">
                            {active}
                        </span>
                    )}
                </Button>

                <div className="ml-auto flex items-center gap-2">
                    <span
                        className="text-xs text-muted-foreground"
                        id="discover-sort-label"
                    >
                        {t('social.sort_label')}
                    </span>
                    {/* One order at a time; "Relevância" is the default. */}
                    <Select
                        value={current.sort}
                        onValueChange={(v) => sortBy(v as DiscoverSort)}
                        disabled={locating}
                    >
                        <SelectTrigger
                            size="sm"
                            aria-labelledby="discover-sort-label"
                            className="min-w-40 rounded-full bg-card"
                        >
                            {locating && (
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                            )}
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent align="end">
                            <SelectItem value="relevance">
                                {t('social.sort_relevance')}
                            </SelectItem>
                            <SelectItem value="distance">
                                {t('social.sort_distance')}
                            </SelectItem>
                            <SelectItem value="rating">
                                {t('social.sort_rating')}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {locating ? (
                <p className="text-xs text-muted-foreground" role="status">
                    {t('social.locating')}
                </p>
            ) : (
                filters.sort === 'distance' &&
                origin !== 'device' && (
                    <p className="text-xs text-muted-foreground" role="status">
                        {origin === 'ip'
                            ? t('social.origin_ip')
                            : t('social.origin_unknown')}
                    </p>
                )
            )}

            {panelOpen && (
                <div
                    id="discover-filters"
                    className="grid gap-5 rounded-2xl border bg-card p-4 shadow-xs sm:grid-cols-3 sm:gap-6"
                >
                    <RangeFilter
                        label={t('social.filter_crowd')}
                        value={crowd}
                        onChange={setCrowd}
                        onCommit={(v) => apply({ crowd: v })}
                        thumbLabels={[
                            t('social.filter_crowd_min'),
                            t('social.filter_crowd_max'),
                        ]}
                        ticks={[
                            t('social.crowd_calm'),
                            '',
                            t('social.crowd_packed'),
                        ]}
                    />
                    <RangeFilter
                        label={t('social.filter_price')}
                        value={price}
                        onChange={setPrice}
                        onCommit={(v) => apply({ price: v })}
                        thumbLabels={[
                            t('social.filter_price_min'),
                            t('social.filter_price_max'),
                        ]}
                        ticks={['$', '$$', '$$$']}
                    />
                    <label className="flex items-center justify-between gap-3 sm:flex-col sm:items-start sm:justify-start">
                        <span className="text-sm font-medium">
                            {t('social.filter_open')}
                        </span>
                        <Switch
                            checked={current.open}
                            onCheckedChange={(open) => apply({ open })}
                        />
                    </label>

                    {active > 0 && (
                        <div className="sm:col-span-3">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={clear}
                                className="-ml-2"
                            >
                                <X className="h-4 w-4" />
                                {t('social.filters_clear')}
                            </Button>
                        </div>
                    )}
                </div>
            )}

            <DiscoverGrid
                restaurants={restaurants}
                loading={loading}
                emptyAction={
                    active > 0
                        ? t('social.filters_clear')
                        : filters.q.trim() !== ''
                          ? t('social.search_clear')
                          : null
                }
                onEmptyAction={clearAll}
            />
        </div>
    );
}

function RangeFilter({
    label,
    value,
    onChange,
    onCommit,
    thumbLabels,
    ticks,
}: {
    label: string;
    value: [number, number];
    onChange: (value: [number, number]) => void;
    onCommit: (value: [number, number]) => void;
    thumbLabels: [string, string];
    /** Under levels 1, 2 and 3. */
    ticks: [string, string, string];
}) {
    return (
        <div className="space-y-3">
            <span className="block text-sm font-medium">{label}</span>
            <Slider
                min={MIN_LEVEL}
                max={MAX_LEVEL}
                step={1}
                minStepsBetweenThumbs={0}
                value={value}
                onValueChange={(v) => onChange([v[0], v[1]])}
                onValueCommit={(v) => onCommit([v[0], v[1]])}
                thumbLabels={thumbLabels}
            />
            <div
                className="flex justify-between text-xs text-muted-foreground"
                aria-hidden
            >
                {ticks.map((tick, i) => (
                    <span
                        key={i}
                        className="min-w-5 text-center first:text-left last:text-right"
                    >
                        {tick}
                    </span>
                ))}
            </div>
        </div>
    );
}

/**
 * The grid of restaurants. Loads more on scroll; the URL stays put, and going back
 * from a profile returns to the same place in the list.
 */
function DiscoverGrid({
    restaurants,
    loading,
    emptyAction,
    onEmptyAction,
}: {
    restaurants: DiscoverRestaurant[];
    loading: boolean;
    /** Label of the empty state's button while a search or filter is on (null: nothing to clear). */
    emptyAction: string | null;
    onEmptyAction: () => void;
}) {
    const { t } = useTranslation();

    if (restaurants.length === 0) {
        return (
            <div className="space-y-3 rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                <p>
                    {emptyAction !== null
                        ? t('social.discover_no_results')
                        : t('social.discover_empty')}
                </p>
                {emptyAction !== null && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onEmptyAction}
                    >
                        {emptyAction}
                    </Button>
                )}
            </div>
        );
    }

    return (
        <InfiniteScroll
            data="discover"
            onlyNext
            preserveUrl
            as="ul"
            className={cn(
                'grid grid-cols-2 gap-3 transition-opacity sm:grid-cols-3 lg:grid-cols-4',
                loading && 'opacity-60',
            )}
            loading={() => (
                <p className="col-span-full py-4 text-center text-sm text-muted-foreground">
                    {t('social.discover_loading')}
                </p>
            )}
        >
            {restaurants.map((r) => (
                <li key={r.id}>
                    <DiscoverCard restaurant={r} />
                </li>
            ))}
        </InfiniteScroll>
    );
}

const numberFormats = new Map<string, Intl.NumberFormat>();

function formatNumber(value: number, language: string, digits: number) {
    const key = `${language}:${digits}`;
    let format = numberFormats.get(key);

    if (!format) {
        format = new Intl.NumberFormat(language.replace('_', '-'), {
            maximumFractionDigits: digits,
        });
        numberFormats.set(key, format);
    }

    return format.format(value);
}

function DiscoverCard({ restaurant: r }: { restaurant: DiscoverRestaurant }) {
    const { t, i18n } = useTranslation();
    const ratingLabel = t('social.rating_label', {
        average: formatNumber(r.rating.average, i18n.language, 1),
        count: r.rating.count,
    });

    return (
        <Link
            href={restaurantProfile.show.url({ handle: r.handle })}
            className="flex h-full flex-col overflow-hidden rounded-xl border bg-card shadow-xs transition-colors hover:bg-muted/40"
        >
            <div className="relative aspect-[2/1] bg-gradient-to-br from-primary/70 via-primary/40 to-primary/15">
                {r.banner_url && (
                    <img
                        src={r.banner_url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        fetchPriority="low"
                        className="h-full w-full object-cover"
                    />
                )}
                {r.has_stories && (
                    <span className="absolute top-1.5 right-1.5 rounded-full bg-background/90 px-2 py-0.5 text-[10px] font-bold text-primary shadow-sm">
                        {t('stories.title')}
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col px-2.5 pb-2.5">
                <div className="flex items-end justify-between gap-1">
                    <span
                        className={cn(
                            'relative z-10 -mt-5 flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border-2 border-background bg-card shadow-xs',
                            r.has_stories &&
                                'ring-2 ring-primary ring-offset-1 ring-offset-card',
                        )}
                    >
                        {r.logo_url ? (
                            <img
                                src={r.logo_url}
                                alt=""
                                loading="lazy"
                                decoding="async"
                                className="h-full w-full object-contain p-0.5"
                            />
                        ) : (
                            <Store className="size-5 text-muted-foreground/60" />
                        )}
                    </span>
                    {r.is_open !== null && (
                        <span
                            className={cn(
                                'mt-1 truncate rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                r.is_open
                                    ? 'bg-primary/15 text-primary'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {r.is_open
                                ? t('venue_profile.open_now')
                                : t('venue_profile.closed_now')}
                        </span>
                    )}
                </div>

                <span className="mt-1.5 truncate text-sm leading-tight font-semibold">
                    {r.name}
                </span>
                <span className="truncate font-receipt text-[11px] text-muted-foreground">
                    @{r.handle}
                </span>

                <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 pt-1.5 text-xs">
                    <span
                        className="flex items-center gap-0.5"
                        role="img"
                        aria-label={ratingLabel}
                        title={ratingLabel}
                    >
                        <Star
                            className={cn(
                                'size-3.5',
                                r.rating.count > 0
                                    ? 'fill-amber-400 text-amber-400'
                                    : 'text-muted-foreground/50',
                            )}
                        />
                        <span className="font-semibold tabular-nums">
                            {r.rating.count > 0
                                ? formatNumber(
                                      r.rating.average,
                                      i18n.language,
                                      1,
                                  )
                                : '–'}
                        </span>
                        {r.rating.count > 0 && (
                            <span className="text-muted-foreground tabular-nums">
                                ({r.rating.count})
                            </span>
                        )}
                    </span>
                    {r.price_level !== null && (
                        <PriceLevelMark level={r.price_level} />
                    )}
                    {r.crowd_level !== null && (
                        <CrowdMeter level={r.crowd_level} />
                    )}
                    {r.distance_km !== null && (
                        <span className="flex items-center gap-0.5 text-muted-foreground">
                            <MapPin className="size-3.5 shrink-0" />
                            {r.distance_km < 1
                                ? t('social.distance_m', {
                                      distance: Math.max(
                                          100,
                                          Math.round(r.distance_km * 10) * 100,
                                      ),
                                  })
                                : t('social.distance_km', {
                                      distance: formatNumber(
                                          r.distance_km,
                                          i18n.language,
                                          r.distance_km < 10 ? 1 : 0,
                                      ),
                                  })}
                        </span>
                    )}
                </div>
            </div>
        </Link>
    );
}

function PriceLevelMark({ level }: { level: PriceLevel }) {
    const { t } = useTranslation();
    const label = t('social.price_level_label', { level });

    return (
        <span
            className="font-semibold tracking-tight"
            role="img"
            aria-label={label}
            title={label}
        >
            <span className="text-foreground">{'$'.repeat(level)}</span>
            <span className="text-muted-foreground/40">
                {'$'.repeat(MAX_LEVEL - level)}
            </span>
        </span>
    );
}

const CROWD_COLORS: Record<CrowdLevel, string> = {
    1: 'text-primary',
    2: 'text-amber-500',
    3: 'text-red-500',
};

/** One, two or three people for the level; the calm and packed ends are also named. */
function CrowdMeter({ level }: { level: CrowdLevel }) {
    const { t } = useTranslation();
    const label = t(`social.crowd_level_${level}`);

    return (
        <span
            className="flex items-center gap-1"
            role="img"
            aria-label={label}
            title={label}
        >
            <span
                className={cn('flex items-center', CROWD_COLORS[level])}
                aria-hidden
            >
                {Array.from({ length: level }, (_, i) => (
                    <UserRound
                        key={i}
                        className={cn(
                            'size-3.5 fill-current',
                            i > 0 && '-ml-1.5',
                        )}
                        strokeWidth={2.5}
                    />
                ))}
            </span>
            {level !== 2 && (
                <span className="text-muted-foreground" aria-hidden>
                    {level === 1
                        ? t('social.crowd_calm')
                        : t('social.crowd_packed')}
                </span>
            )}
        </span>
    );
}
