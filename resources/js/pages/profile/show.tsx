import { Head, InfiniteScroll, Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    EyeOff,
    Moon,
    Pencil,
    Share2,
    Store,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import EstablishmentController from '@/actions/App/Http/Controllers/Restaurant/EstablishmentController';
import MenuController from '@/actions/App/Http/Controllers/Restaurant/MenuController';
import AppLogoWordmark from '@/components/app-logo-wordmark';
import { PostCard } from '@/components/social/post-card';
import { PostComposer } from '@/components/social/post-composer';
import { SocialTopBar } from '@/components/social/social-nav';
import { StarRating } from '@/components/social/star-rating';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type {
    OpeningHourInterval,
    PublicRestaurant,
    RatingSummary,
    RestaurantPost,
    VenuePhoto,
} from '@/types';

type Props = {
    restaurant: PublicRestaurant;
    hours: OpeningHourInterval[];
    /** Null when the restaurant hasn't set its hours. */
    isOpen: boolean | null;
    /** Weekday in the business timezone (0 = Sunday). */
    today: number;
    photos: VenuePhoto[];
    menus: { id: number; name: string }[];
    isOwner: boolean;
    /** False only when the owner hid the profile; then only the owner reaches this page. */
    isPublic: boolean;
    /** Signed in with a verified email: may react and comment. */
    canInteract: boolean;
    suggestedReactions: string[];
    rating: RatingSummary;
    /** One page of the feed; InfiniteScroll merges the next ones in. */
    posts: { data: RestaurantPost[] };
    url: string;
};

/** Brazilian week starts on Monday; weekday numbers follow JS/Carbon (0 = Sunday). */
const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

export default function ProfileShow({
    restaurant,
    hours,
    isOpen,
    today,
    photos,
    menus,
    isOwner,
    isPublic,
    canInteract,
    suggestedReactions,
    rating,
    posts,
    url,
}: Props) {
    const { t } = useTranslation();
    // auth.user is null for guests on public pages, despite the shared type.
    const signedIn = Boolean(usePage().props.auth?.user);
    const [publishing, setPublishing] = useState(false);
    const [tab, setTab] = useState<'posts' | 'about'>('posts');

    const publish = () =>
        router.put(
            EstablishmentController.updateProfile.url(),
            { handle: restaurant.handle, profile_public: true },
            {
                preserveScroll: true,
                onStart: () => setPublishing(true),
                onFinish: () => setPublishing(false),
            },
        );

    const share = async () => {
        if (navigator.share) {
            try {
                await navigator.share({ title: restaurant.name, url });
            } catch {
                // The visitor closed the share sheet.
            }

            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            toast.success(t('venue_profile.link_copied'));
        } catch {
            toast.error(t('venue_profile.link_copy_failed'));
        }
    };

    return (
        <>
            <Head title={restaurant.name} />

            <div className="min-h-screen bg-background pb-12">
                <SocialTopBar />

                {!isPublic && (
                    <div className="border-b bg-muted px-4 py-3">
                        <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-3">
                            <p className="flex items-center gap-2 text-sm">
                                <EyeOff className="h-4 w-4 shrink-0" />
                                {t('venue_profile.hidden_notice')}
                            </p>
                            <Button
                                size="sm"
                                onClick={publish}
                                disabled={publishing}
                            >
                                <Eye className="h-4 w-4" />
                                {t('venue_profile.hidden_action')}
                            </Button>
                        </div>
                    </div>
                )}

                {/* Banner + identity */}
                <header className="mx-auto max-w-3xl sm:px-4 sm:pt-4">
                    <div className="relative aspect-[3/1] overflow-hidden bg-gradient-to-br from-primary/80 via-primary/50 to-primary/20 sm:rounded-2xl">
                        {restaurant.banner_url && (
                            <img
                                src={restaurant.banner_url}
                                alt=""
                                fetchPriority="high"
                                decoding="async"
                                className="h-full w-full object-cover"
                            />
                        )}
                    </div>

                    <div className="px-4 sm:px-2">
                        <div className="-mt-10 flex items-end justify-between gap-3 sm:-mt-14">
                            <div className="relative flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border-4 border-background bg-card shadow-sm sm:size-28">
                                {restaurant.logo_url ? (
                                    <img
                                        src={restaurant.logo_url}
                                        alt={t('venue_profile.logo_alt', {
                                            name: restaurant.name,
                                        })}
                                        decoding="async"
                                        className="h-full w-full object-contain p-1.5"
                                    />
                                ) : (
                                    <Store className="size-8 text-muted-foreground/60" />
                                )}
                            </div>

                            <div className="flex gap-2 pb-1">
                                {isOwner && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={EstablishmentController.edit.url()}
                                        >
                                            <Pencil className="h-4 w-4" />
                                            {t('venue_profile.edit')}
                                        </Link>
                                    </Button>
                                )}
                                {isPublic && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={share}
                                    >
                                        <Share2 className="h-4 w-4" />
                                        {t('venue_profile.share')}
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="mt-3 space-y-2">
                            <h1 className="font-display text-[2rem] leading-none font-extrabold tracking-[-0.005em] text-balance sm:text-[2.5rem]">
                                {restaurant.name}
                            </h1>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span className="font-receipt text-sm text-muted-foreground">
                                    @{restaurant.handle}
                                </span>
                                {isOpen !== null && <OpenBadge open={isOpen} />}
                            </div>
                            <StarRating rating={rating} />
                        </div>

                        {menus.length > 0 && (
                            <div className="mt-5 flex flex-wrap gap-2">
                                {menus.map((menu, i) => (
                                    <Button
                                        key={menu.id}
                                        variant={
                                            i === 0 ? 'default' : 'outline'
                                        }
                                        asChild
                                    >
                                        <Link
                                            href={MenuController.show.url({
                                                menu: menu.id,
                                            })}
                                        >
                                            <BookOpen className="h-4 w-4" />
                                            {menus.length === 1
                                                ? t('venue_profile.see_menu')
                                                : menu.name}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>
                </header>

                <main className="mx-auto mt-6 max-w-3xl px-4 sm:px-6">
                    <div
                        role="tablist"
                        aria-label={t('social.tabs_label')}
                        className="mb-6 flex gap-6 border-b"
                    >
                        {(['posts', 'about'] as const).map((key) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key)}
                                className={cn(
                                    '-mb-px border-b-2 pb-2.5 text-sm font-semibold transition-colors',
                                    tab === key
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t(`social.tab_${key}`)}
                            </button>
                        ))}
                    </div>

                    {tab === 'posts' ? (
                        <div className="mx-auto max-w-xl space-y-4">
                            {isOwner && <PostComposer />}
                            {posts.data.length === 0 ? (
                                <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                                    {isOwner
                                        ? t('social.feed_empty_owner')
                                        : t('social.feed_empty')}
                                </p>
                            ) : (
                                <InfiniteScroll
                                    data="posts"
                                    onlyNext
                                    className="space-y-4"
                                    loading={() => (
                                        <p className="py-4 text-center text-sm text-muted-foreground">
                                            {t('social.feed_loading')}
                                        </p>
                                    )}
                                >
                                    {posts.data.map((post, i) => (
                                        <PostCard
                                            key={post.id}
                                            post={post}
                                            restaurant={restaurant}
                                            isOwner={isOwner}
                                            canInteract={canInteract}
                                            signedIn={signedIn}
                                            suggestedReactions={
                                                suggestedReactions
                                            }
                                            eagerImage={i === 0}
                                        />
                                    ))}
                                </InfiniteScroll>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-8">
                            {photos.length > 0 && <Gallery photos={photos} />}
                            <Hours hours={hours} today={today} />
                            {photos.length === 0 && hours.length === 0 && (
                                <p className="rounded-xl border border-dashed p-5 text-sm text-muted-foreground">
                                    {isOwner
                                        ? t('venue_profile.owner_empty_hint')
                                        : t('social.about_empty')}
                                </p>
                            )}
                        </div>
                    )}
                </main>

                <footer className="mt-12 flex justify-center">
                    <Link
                        href={home.url()}
                        className="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        {t('venue_profile.powered_by')}
                        <AppLogoWordmark className="h-4" />
                    </Link>
                </footer>
            </div>
        </>
    );
}

function OpenBadge({ open }: { open: boolean }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                open
                    ? 'bg-primary/15 text-primary'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            <span
                className={cn(
                    'size-1.5 rounded-full',
                    open ? 'bg-primary' : 'bg-muted-foreground/60',
                )}
            />
            {open ? t('venue_profile.open_now') : t('venue_profile.closed_now')}
        </span>
    );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="mb-3 font-display text-2xl leading-none font-extrabold">
            {children}
        </h2>
    );
}

// ─── Photos ───────────────────────────────────────────────────────────────────

function Gallery({ photos }: { photos: VenuePhoto[] }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState<number | null>(null);
    const current = index === null ? null : photos[index];
    const step = (delta: number) =>
        setIndex((i) =>
            i === null ? null : (i + delta + photos.length) % photos.length,
        );

    return (
        <section>
            <SectionTitle>{t('venue_profile.photos')}</SectionTitle>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {photos.map((photo, i) => (
                    <button
                        key={photo.id}
                        type="button"
                        onClick={() => setIndex(i)}
                        className="aspect-[4/3] overflow-hidden rounded-xl bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <img
                            src={photo.url}
                            alt={t('venue_profile.photo_alt', { n: i + 1 })}
                            loading="lazy"
                            decoding="async"
                            className="h-full w-full object-cover transition-transform hover:scale-[1.03]"
                        />
                    </button>
                ))}
            </div>

            <Dialog
                open={current !== null}
                onOpenChange={(open) => !open && setIndex(null)}
            >
                <DialogContent
                    className="max-w-3xl gap-3 p-3"
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowLeft') {
                            step(-1);
                        }

                        if (e.key === 'ArrowRight') {
                            step(1);
                        }
                    }}
                >
                    <DialogTitle className="sr-only">
                        {t('venue_profile.photos')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {index !== null &&
                            t('venue_profile.photo_position', {
                                current: index + 1,
                                total: photos.length,
                            })}
                    </DialogDescription>
                    {current && (
                        <img
                            src={current.url}
                            alt={t('venue_profile.photo_alt', {
                                n: (index ?? 0) + 1,
                            })}
                            className="aspect-[4/3] w-full rounded-lg object-cover"
                        />
                    )}
                    {photos.length > 1 && (
                        <div className="flex items-center justify-between">
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => step(-1)}
                                aria-label={t('venue_profile.photo_previous')}
                            >
                                <ChevronLeft className="h-5 w-5" />
                            </Button>
                            <span className="font-receipt text-xs text-muted-foreground tabular-nums">
                                {(index ?? 0) + 1}/{photos.length}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => step(1)}
                                aria-label={t('venue_profile.photo_next')}
                            >
                                <ChevronRight className="h-5 w-5" />
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}

// ─── Opening hours ────────────────────────────────────────────────────────────

function Hours({
    hours,
    today,
}: {
    hours: OpeningHourInterval[];
    today: number;
}) {
    const { t } = useTranslation();

    if (hours.length === 0) {
        return null;
    }

    return (
        <section>
            <SectionTitle>
                <span className="inline-flex items-center gap-2">
                    <Clock className="h-5 w-5" />
                    {t('venue_profile.hours')}
                </span>
            </SectionTitle>
            <ul className="divide-y divide-dashed rounded-xl border bg-card px-4">
                {DISPLAY_ORDER.map((weekday) => {
                    const intervals = hours.filter(
                        (h) => h.weekday === weekday,
                    );
                    const isToday = weekday === today;

                    return (
                        <li
                            key={weekday}
                            aria-current={isToday ? 'date' : undefined}
                            className={cn(
                                'flex items-start justify-between gap-4 py-2.5 text-sm',
                                isToday && 'font-semibold',
                            )}
                        >
                            <span className="flex items-center gap-2">
                                {t(`establishment.weekday_${weekday}`)}
                                {isToday && (
                                    <span className="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">
                                        {t('venue_profile.today')}
                                    </span>
                                )}
                            </span>
                            <span className="text-right tabular-nums">
                                {intervals.length === 0 ? (
                                    <span className="text-muted-foreground">
                                        {t('establishment.closed')}
                                    </span>
                                ) : (
                                    intervals.map((h, i) => (
                                        <span
                                            key={i}
                                            className="flex items-center justify-end gap-1"
                                        >
                                            {h.opens_at} – {h.closes_at}
                                            {h.closes_at <= h.opens_at && (
                                                <Moon
                                                    className="h-3 w-3 text-muted-foreground"
                                                    aria-label={t(
                                                        'establishment.overnight',
                                                    )}
                                                />
                                            )}
                                        </span>
                                    ))
                                )}
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
