import { Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    Search,
    Store,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import SocialFeedController from '@/actions/App/Http/Controllers/SocialFeedController';
import { StarRating } from '@/components/social/star-rating';
import { cn } from '@/lib/utils';
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

/** Horizontal strip of restaurants (most recently active first), to browse profiles. */
export function RestaurantStrip({
    restaurants,
}: {
    restaurants: DirectoryRestaurant[];
}) {
    const { t } = useTranslation();

    const listRef = useRef<HTMLUListElement>(null);
    const [edges, setEdges] = useState({ start: false, end: false });

    // Arrows and fades only where there is more to see. A scroll listener plus
    // a ResizeObserver: no polling, nothing runs while the strip sits still.
    useEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        let frame = 0;
        const update = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const max = list.scrollWidth - list.clientWidth;
                const start = list.scrollLeft > 4;
                const end = list.scrollLeft < max - 4;
                setEdges((e) =>
                    e.start === start && e.end === end ? e : { start, end },
                );
            });
        };

        update();
        list.addEventListener('scroll', update, { passive: true });
        const observer =
            typeof ResizeObserver !== 'undefined'
                ? new ResizeObserver(update)
                : null;
        observer?.observe(list);

        return () => {
            cancelAnimationFrame(frame);
            list.removeEventListener('scroll', update);
            observer?.disconnect();
        };
    }, [restaurants.length]);

    if (restaurants.length === 0) {
        return null;
    }

    const page = (direction: 1 | -1) => {
        const list = listRef.current;

        list?.scrollBy({
            left: direction * list.clientWidth * 0.8,
            behavior: 'smooth',
        });
    };

    return (
        <section aria-labelledby="strip-title">
            <h2
                id="strip-title"
                className="mb-2 text-sm font-semibold text-muted-foreground"
            >
                {t('social.strip_title')}
            </h2>
            <div className="relative -mx-4 sm:mx-0">
                <ul
                    ref={listRef}
                    className="flex snap-x snap-mandatory scroll-px-4 [scrollbar-width:none] gap-3 overflow-x-auto overscroll-x-contain px-4 pb-1 sm:scroll-px-0 sm:px-0 [&::-webkit-scrollbar]:hidden"
                >
                    {restaurants.map((r) => (
                        <li key={r.id} className="w-20 shrink-0 snap-start">
                            <Link
                                href={restaurantProfile.show.url({
                                    handle: r.handle,
                                })}
                                className="flex flex-col items-center gap-1.5 rounded-xl text-center"
                            >
                                <Logo
                                    restaurant={r}
                                    className="size-16 rounded-2xl shadow-xs"
                                />
                                <span className="line-clamp-2 text-xs leading-tight font-medium">
                                    {r.name}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>

                {/* Fades hint that the strip continues; arrows for mouse users (touch swipes). */}
                <StripEdge
                    side="start"
                    visible={edges.start}
                    label={t('social.strip_prev')}
                    onClick={() => page(-1)}
                />
                <StripEdge
                    side="end"
                    visible={edges.end}
                    label={t('social.strip_next')}
                    onClick={() => page(1)}
                />
            </div>
        </section>
    );
}

function StripEdge({
    side,
    visible,
    label,
    onClick,
}: {
    side: 'start' | 'end';
    visible: boolean;
    label: string;
    onClick: () => void;
}) {
    const Icon = side === 'start' ? ChevronLeft : ChevronRight;

    return (
        <div
            aria-hidden={!visible}
            className={cn(
                'pointer-events-none absolute inset-y-0 flex w-12 items-start pt-4 transition-opacity duration-200',
                side === 'start'
                    ? 'left-0 justify-start bg-gradient-to-r'
                    : 'right-0 justify-end bg-gradient-to-l',
                'from-background via-background/70 to-transparent',
                visible ? 'opacity-100' : 'opacity-0',
            )}
        >
            <button
                type="button"
                onClick={onClick}
                tabIndex={visible ? 0 : -1}
                aria-label={label}
                title={label}
                className={cn(
                    'hidden size-8 items-center justify-center rounded-full border bg-card text-foreground shadow-sm hover:bg-muted sm:flex',
                    visible && 'pointer-events-auto',
                )}
            >
                <Icon className="h-4 w-4" />
            </button>
        </div>
    );
}
