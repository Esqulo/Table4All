import { Link, router, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Loader2,
    Pause,
    Play,
    Plus,
    Store,
    Trash2,
    Volume2,
    VolumeX,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import StoryController from '@/actions/App/Http/Controllers/Restaurant/StoryController';
import { ImageCropper } from '@/components/image-cropper';
import { ConfirmDialog } from '@/components/social/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLiveReload } from '@/hooks/use-live-reload';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import { timeAgo } from '@/lib/relative-time';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';
import restaurantProfile from '@/routes/restaurant-profile';
import type { Story, StoryGroup, StoryLimits } from '@/types';

/** How long a picture (or the owner's "converting" card) stays on screen. */
const IMAGE_MS = 5000;
/** A press longer than this pauses instead of skipping. */
const HOLD_MS = 200;
/** Full story size: 1080×1920 (9:16). */
const STORY_WIDTH = 1080;
const STORY_ASPECT = 9 / 16;
/** While a video converts, the owner's strip refreshes until it's ready. */
const PROCESSING_RELOAD_MS = 5000;

// ─── Seen stories (this browser only: a convenience, not data) ─────────────────

const SEEN_KEY = 't4a.stories.seen';
const NOTHING_SEEN: ReadonlySet<number> = new Set();
const seenListeners = new Set<() => void>();
let seenSnapshot: ReadonlySet<number> | null = null;

function readSeen(): ReadonlySet<number> {
    try {
        const raw = window.localStorage.getItem(SEEN_KEY);
        const ids: unknown = raw ? JSON.parse(raw) : [];

        return new Set(
            Array.isArray(ids)
                ? ids.filter((id): id is number => typeof id === 'number')
                : [],
        );
    } catch {
        return NOTHING_SEEN;
    }
}

const seenStore = {
    subscribe(listener: () => void): () => void {
        seenListeners.add(listener);

        return () => seenListeners.delete(listener);
    },
    get(): ReadonlySet<number> {
        seenSnapshot ??= readSeen();

        return seenSnapshot;
    },
    /** The server can't know: every ring starts "new", then the browser fills in. */
    getServer(): ReadonlySet<number> {
        return NOTHING_SEEN;
    },
    add(id: number, live: StoryGroup[]): void {
        const current = seenStore.get();

        if (current.has(id)) {
            return;
        }

        // Only ids still live: the list never grows past what the strip shows.
        const liveIds = new Set(
            live.flatMap((g) => g.stories.map((s) => s.id)),
        );
        seenSnapshot = new Set([...current, id].filter((x) => liveIds.has(x)));

        try {
            window.localStorage.setItem(
                SEEN_KEY,
                JSON.stringify([...seenSnapshot]),
            );
        } catch {
            // Private mode or blocked storage: it's remembered until the page reloads.
        }

        seenListeners.forEach((listener) => listener());
    },
};

function firstUnseen(group: StoryGroup, seen: ReadonlySet<number>): number {
    const index = group.stories.findIndex((s) => !seen.has(s.id));

    return index === -1 ? 0 : index;
}

// ─── Strip ──────────────────────────────────────────────────────────────────

/**
 * "Hoje tem!" at the top of the feed: restaurants with stories from the last
 * 24 hours. A restaurant also posts its own from here (the first circle).
 */
export function StoriesStrip({
    groups,
    limits,
}: {
    groups: StoryGroup[];
    /** Null for accounts that can't post (customers). */
    limits: StoryLimits | null;
}) {
    const { t } = useTranslation();
    const ownLogo = usePage().props.auth?.user?.logo_url ?? null;
    const seen = useSyncExternalStore(
        seenStore.subscribe,
        seenStore.get,
        seenStore.getServer,
    );
    const [viewing, setViewing] = useState<number | null>(null);
    const uploadRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLUListElement>(null);
    const edges = useStripEdges(listRef, groups.length);

    const ownIndex = groups.findIndex((g) => g.is_owner);
    const own = ownIndex === -1 ? null : groups[ownIndex];
    const converting =
        own?.stories.some((s) => s.status === 'processing') ?? false;

    useLiveReload({
        only: ['stories'],
        intervalMs: PROCESSING_RELOAD_MS,
        enabled: converting && viewing === null,
    });

    const markSeen = useCallback(
        (id: number) => seenStore.add(id, groups),
        [groups],
    );

    if (groups.length === 0 && limits === null) {
        return null;
    }

    const others = groups
        .map((group, index) => ({ group, index }))
        .filter(({ group }) => !group.is_owner);

    const page = (direction: 1 | -1) => {
        const list = listRef.current;

        list?.scrollBy({
            left: direction * list.clientWidth * 0.8,
            behavior: 'smooth',
        });
    };

    return (
        <section aria-labelledby="stories-title">
            <h2
                id="stories-title"
                className="mb-2 font-display text-xl leading-none font-extrabold"
            >
                {t('stories.title')}
            </h2>
            <div className="relative -mx-4 sm:mx-0">
                <ul
                    ref={listRef}
                    className="flex snap-x snap-mandatory scroll-px-4 [scrollbar-width:none] gap-3 overflow-x-auto overscroll-x-contain px-4 pt-0.5 pb-1 sm:scroll-px-0 sm:px-0 [&::-webkit-scrollbar]:hidden"
                >
                    {limits && (
                        <li className="relative w-20 shrink-0 snap-start">
                            <button
                                type="button"
                                onClick={() =>
                                    own
                                        ? setViewing(ownIndex)
                                        : uploadRef.current?.click()
                                }
                                className="flex w-full flex-col items-center gap-1.5 text-center"
                                aria-label={
                                    own
                                        ? t('stories.open_own')
                                        : t('stories.add')
                                }
                            >
                                <Ring
                                    logoUrl={
                                        own?.restaurant.logo_url ?? ownLogo
                                    }
                                    state={
                                        own
                                            ? own.stories.every((s) =>
                                                  seen.has(s.id),
                                              )
                                                ? 'seen'
                                                : 'new'
                                            : 'none'
                                    }
                                    busy={converting}
                                />
                                <span className="line-clamp-2 text-xs leading-tight font-medium">
                                    {t('stories.yours')}
                                </span>
                            </button>
                            <button
                                type="button"
                                onClick={() => uploadRef.current?.click()}
                                aria-label={t('stories.add')}
                                title={t('stories.add')}
                                className="absolute top-12 right-2 flex size-6 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground shadow-sm"
                            >
                                <Plus className="size-3.5" strokeWidth={3} />
                            </button>
                        </li>
                    )}

                    {others.map(({ group, index }) => (
                        <li
                            key={group.restaurant.id}
                            className="w-20 shrink-0 snap-start"
                        >
                            <button
                                type="button"
                                onClick={() => setViewing(index)}
                                className="flex w-full flex-col items-center gap-1.5 text-center"
                                aria-label={t('stories.open', {
                                    name: group.restaurant.name,
                                })}
                            >
                                <Ring
                                    logoUrl={group.restaurant.logo_url}
                                    state={
                                        group.stories.every((s) =>
                                            seen.has(s.id),
                                        )
                                            ? 'seen'
                                            : 'new'
                                    }
                                />
                                <span className="line-clamp-2 text-xs leading-tight font-medium">
                                    {group.restaurant.name}
                                </span>
                            </button>
                        </li>
                    ))}

                    {others.length === 0 && (
                        <li className="flex min-h-20 items-center pr-4 text-sm text-muted-foreground">
                            {limits ? t('stories.empty_owner') : null}
                        </li>
                    )}
                </ul>

                <StripEdge
                    side="start"
                    visible={edges.start}
                    label={t('stories.strip_prev')}
                    onClick={() => page(-1)}
                />
                <StripEdge
                    side="end"
                    visible={edges.end}
                    label={t('stories.strip_next')}
                    onClick={() => page(1)}
                />
            </div>

            {limits && <StoryUploader inputRef={uploadRef} limits={limits} />}

            {viewing !== null && groups[viewing] && (
                <StoryViewer
                    groups={groups}
                    startGroup={viewing}
                    seen={seen}
                    onSeen={markSeen}
                    onClose={() => setViewing(null)}
                />
            )}
        </section>
    );
}

function Ring({
    logoUrl,
    state,
    busy = false,
}: {
    logoUrl: string | null;
    /** `new`: unseen stories; `seen`: all watched; `none`: nothing posted. */
    state: 'new' | 'seen' | 'none';
    busy?: boolean;
}) {
    return (
        <span
            className={cn(
                'relative flex size-[4.25rem] items-center justify-center rounded-full p-[3px]',
                state === 'new' &&
                    'bg-gradient-to-tr from-amber-400 via-primary to-emerald-600',
                state === 'seen' && 'bg-border',
                state === 'none' && 'border-2 border-dashed border-border',
            )}
        >
            <span className="flex size-full items-center justify-center overflow-hidden rounded-full border-2 border-background bg-muted">
                {logoUrl ? (
                    <img
                        src={logoUrl}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="h-full w-full object-contain p-1"
                    />
                ) : (
                    <Store className="size-5 text-muted-foreground/60" />
                )}
            </span>
            {busy && (
                <span className="absolute inset-0 flex items-center justify-center rounded-full bg-background/60">
                    <Loader2 className="size-5 animate-spin text-primary" />
                </span>
            )}
        </span>
    );
}

/** Whether the strip can scroll further each way; drives the fades and arrows. */
function useStripEdges(
    listRef: React.RefObject<HTMLUListElement | null>,
    itemCount: number,
): { start: boolean; end: boolean } {
    const [edges, setEdges] = useState({ start: false, end: false });

    // A scroll listener plus a ResizeObserver: no polling, nothing runs while the strip sits still.
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
    }, [listRef, itemCount]);

    return edges;
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
                'pointer-events-none absolute inset-y-0 flex w-12 items-start pt-5 transition-opacity duration-200',
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

// ─── Upload ──────────────────────────────────────────────────────────────────

/** Reads a video's length in the browser; null when the browser can't (the server checks anyway). */
function videoSeconds(url: string): Promise<number | null> {
    return new Promise((resolve) => {
        const video = document.createElement('video');
        const done = (value: number | null) => {
            video.removeAttribute('src');
            video.load();
            resolve(value);
        };

        video.preload = 'metadata';
        video.muted = true;
        video.onloadedmetadata = () =>
            done(Number.isFinite(video.duration) ? video.duration : null);
        video.onerror = () => done(null);
        video.src = url;
    });
}

/**
 * Picks a picture or a video and sends it. Pictures go through the cropper (9:16,
 * re-encoded small in the browser); videos are checked for size and length before
 * a single byte is uploaded, then the server converts them.
 */
function StoryUploader({
    inputRef,
    limits,
}: {
    inputRef: React.RefObject<HTMLInputElement | null>;
    limits: StoryLimits;
}) {
    const { t } = useTranslation();
    const [cropping, setCropping] = useState<string | null>(null);
    const [video, setVideo] = useState<{ file: File; url: string } | null>(
        null,
    );
    const [progress, setProgress] = useState<number | null>(null);
    // One key per story being sent, kept across retries until it's published.
    const [requestKey, setRequestKey] = useState(newRequestKey);

    useEffect(
        () => () => {
            if (video) {
                URL.revokeObjectURL(video.url);
            }
        },
        [video],
    );

    // `video/*` lets phones offer the camera; the server decides what it can convert.
    const accept = limits.videoEnabled ? 'image/*,video/*' : 'image/*';

    const pick = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';

        if (!file) {
            return;
        }

        if (!file.type.startsWith('video/')) {
            const src = imageSourceUrl(file);

            if (src) {
                setCropping(src);
            }

            return;
        }

        if (!limits.videoEnabled) {
            toast.error(t('stories.error_type'));

            return;
        }

        if (file.size > limits.maxVideoMb * 1024 * 1024) {
            toast.error(t('stories.error_too_big', { mb: limits.maxVideoMb }));

            return;
        }

        const url = URL.createObjectURL(file);
        const seconds = await videoSeconds(url);

        if (seconds !== null && seconds > limits.maxVideoSeconds + 0.5) {
            URL.revokeObjectURL(url);
            toast.error(
                t('stories.error_video_too_long', {
                    seconds: limits.maxVideoSeconds,
                }),
            );

            return;
        }

        setVideo({ file, url });
    };

    const send = (file: File, onDone: () => void) => {
        router.post(
            StoryController.store.url(),
            { media: file, request_key: requestKey },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProgress(0),
                onProgress: (event) =>
                    setProgress(Math.round(event?.percentage ?? 0)),
                onSuccess: () => {
                    setRequestKey(newRequestKey());
                    onDone();
                },
                onError: (errors) =>
                    toast.error(errors.media ?? t('stories.upload_failed')),
                onFinish: () => setProgress(null),
            },
        );
    };

    const sending = progress !== null;

    return (
        <>
            <input
                ref={inputRef}
                type="file"
                accept={accept}
                className="hidden"
                onChange={pick}
            />

            {cropping && (
                <ImageCropper
                    open
                    src={cropping}
                    aspect={STORY_ASPECT}
                    outputWidth={STORY_WIDTH}
                    format="jpeg"
                    quality={0.85}
                    description={t('stories.lifetime_hint')}
                    confirmLabel={t('stories.publish')}
                    onConfirm={(blob) => {
                        URL.revokeObjectURL(cropping);
                        setCropping(null);
                        send(croppedFile(blob, 'story'), () => undefined);
                    }}
                    onCancel={() => {
                        URL.revokeObjectURL(cropping);
                        setCropping(null);
                    }}
                />
            )}

            <Dialog
                open={video !== null}
                onOpenChange={(open) => {
                    if (!open && !sending) {
                        setVideo(null);
                    }
                }}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('stories.video_title')}</DialogTitle>
                        <DialogDescription>
                            {t('stories.lifetime_hint')}
                        </DialogDescription>
                    </DialogHeader>
                    {video && (
                        <video
                            src={video.url}
                            controls
                            muted
                            playsInline
                            className="max-h-[55dvh] w-full rounded-lg bg-black object-contain"
                        />
                    )}
                    {sending && (
                        <div
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={progress}
                            className="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full bg-primary transition-[width]"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setVideo(null)}
                            disabled={sending}
                        >
                            {t('stories.cancel')}
                        </Button>
                        <Button
                            onClick={() =>
                                video && send(video.file, () => setVideo(null))
                            }
                            disabled={sending}
                        >
                            {sending
                                ? t('stories.sending', { percent: progress })
                                : t('stories.publish')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* A picture is sent right after cropping: show that it's on its way. */}
            {sending && video === null && (
                <p
                    role="status"
                    className="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Loader2 className="size-3.5 animate-spin" />
                    {t('stories.sending', { percent: progress })}
                </p>
            )}
        </>
    );
}

// ─── Viewer ──────────────────────────────────────────────────────────────────

/**
 * Full-screen player: pictures stay 5 s, videos play to the end, then the next
 * story (and the next restaurant). Tap the left third to go back, elsewhere to
 * go forward; hold to pause. Arrow keys work too, Esc closes.
 */
function StoryViewer({
    groups,
    startGroup,
    seen,
    onSeen,
    onClose,
}: {
    groups: StoryGroup[];
    startGroup: number;
    seen: ReadonlySet<number>;
    onSeen: (id: number) => void;
    onClose: () => void;
}) {
    const { t, i18n } = useTranslation();
    const [position, setPosition] = useState(() => ({
        group: startGroup,
        story: firstUnseen(groups[startGroup], seen),
    }));
    const [elapsed, setElapsed] = useState(0);
    const [videoProgress, setVideoProgress] = useState(0);
    const [held, setHeld] = useState(false);
    const [userPaused, setUserPaused] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [muted, setMuted] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const videoRef = useRef<HTMLVideoElement>(null);
    // The picture clock: the ref is the truth, the state only redraws the bar.
    const elapsedRef = useRef(0);
    const nextRef = useRef<() => void>(() => undefined);
    const holdTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    const heldRef = useRef(false);
    // The restaurant on screen: if its group disappears (owner deleted its last story), close.
    const restaurantId = useRef(groups[startGroup].restaurant.id);

    const group = groups[position.group] as StoryGroup | undefined;
    const story =
        group?.stories[
            Math.min(position.story, (group?.stories.length ?? 1) - 1)
        ];

    useEffect(() => {
        if (!group || group.restaurant.id !== restaurantId.current || !story) {
            onClose();
        }
    }, [group, story, onClose]);

    const playable = story?.status === 'ready' && story.url !== null;
    const isVideo = playable && story?.media_type === 'video';
    const paused = held || userPaused || confirming || deleting;

    const goTo = useCallback(
        (groupIndex: number, storyIndex: number) => {
            restaurantId.current = groups[groupIndex].restaurant.id;
            setPosition({ group: groupIndex, story: storyIndex });
            elapsedRef.current = 0;
            setElapsed(0);
            setVideoProgress(0);
            setLoaded(false);
        },
        [groups],
    );

    const next = useCallback(() => {
        if (!group) {
            return;
        }

        if (position.story < group.stories.length - 1) {
            goTo(position.group, position.story + 1);
        } else if (position.group < groups.length - 1) {
            goTo(
                position.group + 1,
                firstUnseen(groups[position.group + 1], seen),
            );
        } else {
            onClose();
        }
    }, [group, groups, position, seen, goTo, onClose]);

    const prev = useCallback(() => {
        if (position.story > 0) {
            goTo(position.group, position.story - 1);
        } else if (position.group > 0) {
            goTo(position.group - 1, 0);
        } else {
            goTo(position.group, 0);

            if (videoRef.current) {
                videoRef.current.currentTime = 0;
            }
        }
    }, [position, goTo]);

    useEffect(() => {
        nextRef.current = next;
    }, [next]);

    useEffect(() => {
        if (story && playable) {
            onSeen(story.id);
        }
    }, [story, playable, onSeen]);

    // Pictures and the owner's status cards run on a clock; videos report their own progress.
    useEffect(() => {
        if (isVideo || paused || (playable && !loaded)) {
            return;
        }

        let frame = 0;
        let last = performance.now();
        const tick = (now: number) => {
            elapsedRef.current += now - last;
            last = now;
            setElapsed(elapsedRef.current);

            if (elapsedRef.current >= IMAGE_MS) {
                nextRef.current();

                return;
            }

            frame = requestAnimationFrame(tick);
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [isVideo, paused, playable, loaded, story?.id]);

    useEffect(() => {
        const video = videoRef.current;

        if (!video || !isVideo) {
            return;
        }

        if (paused) {
            video.pause();
        } else {
            // Browsers may refuse sound without a fresh tap: retry muted.
            video.play().catch(() => {
                setMuted(true);
                video.muted = true;
                void video.play().catch(() => undefined);
            });
        }
    }, [paused, isVideo, story?.id]);

    // Warm up the next picture so it shows at once.
    useEffect(() => {
        const after =
            group?.stories[position.story + 1] ??
            groups[position.group + 1]?.stories[0];

        if (
            after?.status === 'ready' &&
            after.media_type === 'image' &&
            after.url
        ) {
            const img = new Image();
            img.src = after.url;
        }
    }, [group, groups, position]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (confirming) {
                return;
            }

            if (e.key === 'ArrowRight') {
                next();
            } else if (e.key === 'ArrowLeft') {
                prev();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [next, prev, confirming]);

    // Hidden tab: stop the clock and the sound.
    useEffect(() => {
        const onVisibility = () => setUserPaused(document.hidden);
        document.addEventListener('visibilitychange', onVisibility);

        return () =>
            document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    if (!group || !story) {
        return null;
    }

    const progress = isVideo ? videoProgress : Math.min(1, elapsed / IMAGE_MS);

    const onPointerDown = () => {
        heldRef.current = false;
        holdTimer.current = setTimeout(() => {
            heldRef.current = true;
            setHeld(true);
        }, HOLD_MS);
    };

    const onPointerUp = (e: React.PointerEvent<HTMLDivElement>) => {
        clearTimeout(holdTimer.current);

        if (heldRef.current) {
            heldRef.current = false;
            setHeld(false);

            return;
        }

        const box = e.currentTarget.getBoundingClientRect();

        if (e.clientX - box.left < box.width / 3) {
            prev();
        } else {
            next();
        }
    };

    const remove = () =>
        router.delete(StoryController.destroy.url({ story: story.id }), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setDeleting(true),
            onSuccess: () => {
                setConfirming(false);
                elapsedRef.current = 0;
                setElapsed(0);
                setVideoProgress(0);
                setLoaded(false);
            },
            onFinish: () => setDeleting(false),
        });

    const profileUrl = restaurantProfile.show.url({
        handle: group.restaurant.handle,
    });

    return (
        <DialogPrimitive.Root open onOpenChange={(open) => !open && onClose()}>
            <DialogPrimitive.Portal>
                <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/95" />
                <DialogPrimitive.Content
                    className="fixed inset-0 z-50 flex items-center justify-center outline-none"
                    onOpenAutoFocus={(e) => e.preventDefault()}
                >
                    <DialogPrimitive.Title className="sr-only">
                        {t('stories.viewer_title', {
                            name: group.restaurant.name,
                        })}
                    </DialogPrimitive.Title>
                    <DialogPrimitive.Description className="sr-only">
                        {t('stories.viewer_hint')}
                    </DialogPrimitive.Description>

                    <div className="relative flex h-dvh w-full max-w-[calc(100dvh*9/16)] flex-col overflow-hidden bg-black text-white sm:h-[min(100dvh,52rem)] sm:max-w-[calc(min(100dvh,52rem)*9/16)] sm:rounded-2xl">
                        {/* Media, with the tap zones over it. */}
                        <div
                            className="absolute inset-0 flex touch-none items-center justify-center select-none"
                            onPointerDown={onPointerDown}
                            onPointerUp={onPointerUp}
                            onPointerCancel={() => {
                                clearTimeout(holdTimer.current);
                                setHeld(false);
                            }}
                            onContextMenu={(e) => e.preventDefault()}
                        >
                            {playable && story.media_type === 'image' && (
                                <img
                                    key={story.id}
                                    src={story.url ?? undefined}
                                    alt={t('stories.image_alt', {
                                        name: group.restaurant.name,
                                    })}
                                    decoding="async"
                                    draggable={false}
                                    onLoad={() => setLoaded(true)}
                                    onError={() => setLoaded(true)}
                                    className="h-full w-full object-contain"
                                />
                            )}
                            {isVideo && (
                                <video
                                    key={story.id}
                                    ref={videoRef}
                                    src={story.url ?? undefined}
                                    poster={story.poster_url ?? undefined}
                                    autoPlay
                                    playsInline
                                    muted={muted}
                                    preload="auto"
                                    onTimeUpdate={(e) => {
                                        const v = e.currentTarget;
                                        setVideoProgress(
                                            v.duration > 0
                                                ? v.currentTime / v.duration
                                                : 0,
                                        );
                                    }}
                                    onEnded={next}
                                    onError={next}
                                    className="h-full w-full object-contain"
                                />
                            )}
                            {!playable && <StatusCard status={story.status} />}
                            {playable &&
                                !loaded &&
                                story.media_type === 'image' && (
                                    <Loader2 className="absolute size-8 animate-spin text-white/70" />
                                )}
                        </div>

                        {/* Top: progress, restaurant, controls. */}
                        <div className="pointer-events-none relative z-10 bg-gradient-to-b from-black/60 to-transparent px-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-6">
                            <div className="flex gap-1">
                                {group.stories.map((s, i) => (
                                    <div
                                        key={s.id}
                                        className="h-0.5 flex-1 overflow-hidden rounded-full bg-white/35"
                                    >
                                        <div
                                            className="h-full bg-white"
                                            style={{
                                                width: `${
                                                    i < position.story
                                                        ? 100
                                                        : i === position.story
                                                          ? progress * 100
                                                          : 0
                                                }%`,
                                            }}
                                        />
                                    </div>
                                ))}
                            </div>
                            <div className="mt-3 flex items-center gap-2">
                                <Link
                                    href={profileUrl}
                                    onClick={onClose}
                                    className="pointer-events-auto flex min-w-0 items-center gap-2"
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-white">
                                        {group.restaurant.logo_url ? (
                                            <img
                                                src={group.restaurant.logo_url}
                                                alt=""
                                                className="h-full w-full object-contain p-0.5"
                                            />
                                        ) : (
                                            <Store className="size-4 text-neutral-500" />
                                        )}
                                    </span>
                                    <span className="truncate text-sm font-semibold">
                                        {group.restaurant.name}
                                    </span>
                                </Link>
                                <span className="shrink-0 text-xs text-white/70">
                                    {timeAgo(story.created_at, i18n.language)}
                                </span>
                                <span className="sr-only">
                                    {t('stories.position', {
                                        current: position.story + 1,
                                        total: group.stories.length,
                                    })}
                                </span>

                                <div className="pointer-events-auto ml-auto flex items-center">
                                    {isVideo && (
                                        <ViewerButton
                                            label={
                                                muted
                                                    ? t('stories.unmute')
                                                    : t('stories.mute')
                                            }
                                            onClick={() => setMuted((m) => !m)}
                                        >
                                            {muted ? <VolumeX /> : <Volume2 />}
                                        </ViewerButton>
                                    )}
                                    <ViewerButton
                                        label={
                                            userPaused
                                                ? t('stories.play')
                                                : t('stories.pause')
                                        }
                                        onClick={() => setUserPaused((p) => !p)}
                                    >
                                        {userPaused ? <Play /> : <Pause />}
                                    </ViewerButton>
                                    {group.is_owner && (
                                        <ViewerButton
                                            label={t('stories.delete')}
                                            onClick={() => setConfirming(true)}
                                        >
                                            <Trash2 />
                                        </ViewerButton>
                                    )}
                                    <ViewerButton
                                        label={t('stories.close')}
                                        onClick={onClose}
                                    >
                                        <X />
                                    </ViewerButton>
                                </div>
                            </div>
                        </div>
                    </div>

                    <ConfirmDialog
                        open={confirming}
                        onOpenChange={setConfirming}
                        title={t('stories.delete_title')}
                        description={t('stories.delete_description')}
                        confirmLabel={t('stories.delete')}
                        onConfirm={remove}
                        processing={deleting}
                    />
                </DialogPrimitive.Content>
            </DialogPrimitive.Portal>
        </DialogPrimitive.Root>
    );
}

function ViewerButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            title={label}
            className="flex size-9 items-center justify-center rounded-full text-white hover:bg-white/15 [&_svg]:size-5"
        >
            {children}
        </button>
    );
}

/** What the owner sees in place of a video that isn't ready (others never get these). */
function StatusCard({ status }: { status: Story['status'] }) {
    const { t } = useTranslation();
    const failed = status === 'failed';

    return (
        <div className="mx-8 flex flex-col items-center gap-3 text-center">
            {failed ? (
                <AlertTriangle className="size-10 text-amber-400" />
            ) : (
                <Loader2 className="size-10 animate-spin text-white/80" />
            )}
            <p className="text-base font-semibold">
                {failed ? t('stories.failed') : t('stories.processing')}
            </p>
            <p className="text-sm text-white/70">
                {failed
                    ? t('stories.failed_hint')
                    : t('stories.processing_hint')}
            </p>
        </div>
    );
}
