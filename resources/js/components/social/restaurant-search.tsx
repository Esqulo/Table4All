import { InfiniteScroll, Link, router, useRemember } from '@inertiajs/react';
import { Loader2, MapPin, Search, Store, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import { StarRating } from '@/components/social/star-rating';
import { Button } from '@/components/ui/button';
import { SEARCH_HASH } from '@/lib/social';
import { cn } from '@/lib/utils';
import restaurantProfile from '@/routes/restaurant-profile';
import type { DirectoryRestaurant, DiscoverRestaurant } from '@/types';

/** Waits for a pause in typing before asking the server (slow mobile data). */
const DEBOUNCE_MS = 300;

/** The #buscar focus happens once per page load (see RestaurantSearchBox). */
let hashFocusDone = false;

/**
 * Search box of the feed. Focusing it opens the list of restaurants to browse
 * (?explorar=1, a new history entry: the phone's back button closes it again).
 * Typing reloads only the search props, so the list already scrolled stays as it
 * was; the term is kept in the URL (?q=).
 */
export function RestaurantSearchBox({
    initial,
    exploring,
}: {
    initial: string;
    /** The list to browse is open. */
    exploring: boolean;
}) {
    const { t } = useTranslation();
    const [value, setValue] = useState(initial);
    const [searching, setSearching] = useState(false);
    // The term the page's results are for.
    const lastSent = useRef(initial.trim());
    const inputRef = useRef<HTMLInputElement>(null);

    // Arriving from the nav's search button (#buscar): focus the box. After mounting, not
    // while rendering (the server never sees the hash), and once per page load, so coming
    // back to this entry later doesn't pop the keyboard up again.
    useEffect(() => {
        if (hashFocusDone || window.location.hash !== SEARCH_HASH) {
            return;
        }

        hashFocusDone = true;
        inputRef.current?.focus();
    }, []);
    // Kept in this history entry: whether it was opened from the feed (then "Cancel" goes back to it).
    const [openedFromFeed, setOpenedFromFeed] = useRemember(
        false,
        'feed-explore-opened',
    );

    useEffect(() => {
        const q = value.trim();

        // Only when the term really changed. Mounting (the server already answered for
        // `initial`), React running effects twice in development, or coming back to the
        // page must not send a search: it would switch the feed to the list.
        if (q === lastSent.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            lastSent.current = q;

            router.get(
                SocialFeedController.index.url(),
                { explorar: 1, ...(q ? { q } : {}) },
                {
                    only: ['q', 'searchResults', 'exploring'],
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onStart: () => setSearching(true),
                    onFinish: () => setSearching(false),
                },
            );
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [value]);

    const explore = () => {
        if (exploring) {
            return;
        }

        router.get(
            SocialFeedController.index.url(),
            { explorar: 1 },
            {
                only: ['exploring', 'discover'],
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => setOpenedFromFeed(true),
            },
        );
    };

    const close = () => {
        if (openedFromFeed) {
            // The feed is the previous entry, as it was (posts loaded, scroll position).
            window.history.back();
        } else {
            router.get(SocialFeedController.index.url(), {}, { replace: true });
        }
    };

    return (
        <div className="flex items-center gap-2">
            <div className="relative flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    ref={inputRef}
                    type="search"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    onFocus={explore}
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
                    value !== '' && (
                        <button
                            type="button"
                            onClick={() => setValue('')}
                            aria-label={t('social.search_clear')}
                            className="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-muted"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    )
                )}
            </div>
            {exploring && (
                <Button type="button" variant="ghost" onClick={close}>
                    {t('social.search_cancel')}
                </Button>
            )}
        </div>
    );
}

function Logo({
    restaurant,
    className,
}: {
    restaurant: DirectoryRestaurant;
    className: string;
}) {
    return (
        <span
            className={`flex shrink-0 items-center justify-center overflow-hidden border bg-muted ${className}`}
        >
            {restaurant.logo_url ? (
                <img
                    src={restaurant.logo_url}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="h-full w-full object-contain p-0.5"
                />
            ) : (
                <Store className="size-5 text-muted-foreground/60" />
            )}
        </span>
    );
}

export function RestaurantSearchResults({
    results,
}: {
    results: DirectoryRestaurant[];
}) {
    const { t } = useTranslation();

    if (results.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                {t('social.search_empty')}
            </p>
        );
    }

    return (
        <ul className="divide-y rounded-2xl border bg-card shadow-xs">
            {results.map((r) => (
                <li key={r.id}>
                    <Link
                        href={restaurantProfile.show.url({ handle: r.handle })}
                        className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
                    >
                        <Logo restaurant={r} className="size-12 rounded-xl" />
                        <span className="min-w-0 flex-1">
                            <span className="block truncate font-semibold">
                                {r.name}
                            </span>
                            <span className="block truncate font-receipt text-xs text-muted-foreground">
                                @{r.handle}
                            </span>
                            <StarRating
                                rating={r.rating}
                                className="mt-1 text-xs"
                            />
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/**
 * Restaurants to browse while nothing is typed, the most interesting first
 * (RestaurantDirectory::discover). Loads more on scroll; the URL stays put,
 * and going back from a profile returns to the same place in the list.
 */
export function DiscoverList({
    restaurants,
}: {
    restaurants: DiscoverRestaurant[];
}) {
    const { t } = useTranslation();

    return (
        <section aria-labelledby="discover-title" className="space-y-3">
            <div>
                <h2
                    id="discover-title"
                    className="font-display text-xl leading-none font-extrabold"
                >
                    {t('social.discover_title')}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {t('social.discover_description')}
                </p>
            </div>

            {restaurants.length === 0 ? (
                <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    {t('social.discover_empty')}
                </p>
            ) : (
                <InfiniteScroll
                    data="discover"
                    onlyNext
                    preserveUrl
                    as="ul"
                    className="space-y-3"
                    loading={() => (
                        <p className="py-4 text-center text-sm text-muted-foreground">
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
            )}
        </section>
    );
}

function DiscoverCard({ restaurant: r }: { restaurant: DiscoverRestaurant }) {
    const { t } = useTranslation();

    return (
        <Link
            href={restaurantProfile.show.url({ handle: r.handle })}
            className="block overflow-hidden rounded-2xl border bg-card shadow-xs transition-colors hover:bg-muted/40"
        >
            <div className="relative aspect-[3/1] bg-gradient-to-br from-primary/70 via-primary/40 to-primary/15">
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
                    <span className="absolute top-2 right-2 rounded-full bg-background/90 px-2.5 py-0.5 text-xs font-bold text-primary shadow-sm">
                        {t('stories.title')}
                    </span>
                )}
            </div>
            <div className="flex gap-3 px-4 pb-3">
                <Logo
                    restaurant={r}
                    className={cn(
                        'relative z-10 -mt-6 size-14 rounded-xl border-2 border-background bg-card shadow-xs',
                        r.has_stories &&
                            'ring-2 ring-primary ring-offset-2 ring-offset-card',
                    )}
                />
                <div className="min-w-0 flex-1 pt-2">
                    <div className="flex items-start justify-between gap-2">
                        <span className="min-w-0">
                            <span className="block truncate font-semibold">
                                {r.name}
                            </span>
                            <span className="block truncate font-receipt text-xs text-muted-foreground">
                                @{r.handle}
                            </span>
                        </span>
                        {r.is_open !== null && (
                            <span
                                className={cn(
                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold',
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
                    <StarRating rating={r.rating} className="mt-1 text-xs" />
                    {r.address && (
                        <span className="mt-1 flex items-start gap-1 text-xs text-muted-foreground">
                            <MapPin className="mt-px h-3.5 w-3.5 shrink-0" />
                            <span className="line-clamp-1">{r.address}</span>
                        </span>
                    )}
                </div>
            </div>
        </Link>
    );
}
