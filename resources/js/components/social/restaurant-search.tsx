import { Link, router } from '@inertiajs/react';
import { Loader2, Search, Store, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import { StarRating } from '@/components/social/star-rating';
import restaurantProfile from '@/routes/restaurant-profile';
import type { DirectoryRestaurant } from '@/types';

/** Waits for a pause in typing before asking the server (slow mobile data). */
const DEBOUNCE_MS = 300;

/**
 * Search box of the feed. Reloads only the search props, so the posts
 * already scrolled stay as they were; the term is kept in the URL (?q=).
 */
export function RestaurantSearchBox({
    initial,
    autoFocus,
}: {
    initial: string;
    autoFocus?: boolean;
}) {
    const { t } = useTranslation();
    const [value, setValue] = useState(initial);
    const [searching, setSearching] = useState(false);
    const first = useRef(true);

    useEffect(() => {
        // The first render already has the server's results for `initial`.
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            const q = value.trim();

            router.get(SocialFeedController.index.url(), q ? { q } : {}, {
                only: ['q', 'searchResults'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            });
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [value]);

    return (
        <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
                type="search"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={t('social.search_placeholder')}
                aria-label={t('social.search_placeholder')}
                autoFocus={autoFocus}
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
