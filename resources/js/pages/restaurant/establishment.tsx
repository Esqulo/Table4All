import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Copy,
    ImagePlus,
    Moon,
    Plus,
    Store,
    Trash2,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import EstablishmentController from '@/actions/App/Http/Controllers/Restaurant/EstablishmentController';
import Heading from '@/components/heading';
import { ImageCropper } from '@/components/image-cropper';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { croppedFile, imageSourceUrl } from '@/lib/image-upload';
import type { OpeningHourInterval, VenuePhoto } from '@/types';

type Props = {
    logoUrl: string | null;
    hours: OpeningHourInterval[];
    photos: VenuePhoto[];
    maxPhotos: number;
};

type Interval = { opens_at: string; closes_at: string };

/** Brazilian week starts on Monday; weekday numbers follow JS/Carbon (0 = Sunday). */
const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];
const MAX_INTERVALS_PER_DAY = 3;
const DEFAULT_INTERVAL: Interval = { opens_at: '18:00', closes_at: '23:00' };
/** Logos are shown small; 512 px covers 3x screens. WebP keeps transparency. */
const LOGO_SIZE = 512;
/** Venue photos are shown in 4:3 tiles and can go full width on a phone. */
const PHOTO_ASPECT = 4 / 3;
const PHOTO_WIDTH = 1600;

export default function Establishment({
    logoUrl,
    hours,
    photos,
    maxPhotos,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('establishment.title')} />

            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
                <Heading
                    title={t('establishment.title')}
                    description={t('establishment.description')}
                />

                <LogoSection logoUrl={logoUrl} />
                <HoursSection initial={hours} />
                <PhotosSection photos={photos} maxPhotos={maxPhotos} />
            </div>
        </>
    );
}

function Section({
    title,
    description,
    aside,
    children,
}: {
    title: string;
    description: string;
    aside?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card shadow-xs">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-dashed px-5 py-4">
                <div className="min-w-0">
                    <h2 className="font-display text-2xl leading-none font-extrabold">
                        {title}
                    </h2>
                    <p className="mt-1.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {aside}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

// ─── Logo ─────────────────────────────────────────────────────────────────────

function LogoSection({ logoUrl }: { logoUrl: string | null }) {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    const [cropSrc, setCropSrc] = useState<string | null>(null);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const src = imageSourceUrl(e.target.files?.[0]);
        e.target.value = '';

        if (src) {
            setCropSrc(src);
        }
    };

    const closeCropper = () => {
        if (cropSrc) {
            URL.revokeObjectURL(cropSrc);
        }

        setCropSrc(null);
    };

    const upload = (blob: Blob) => {
        closeCropper();

        router.post(
            EstablishmentController.updateLogo.url(),
            { logo: croppedFile(blob, 'logo') },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(undefined);
                },
                onError: (errors) => setError(errors.logo),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const remove = () => {
        router.delete(EstablishmentController.destroyLogo.url(), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <Section
            title={t('establishment.logo_title')}
            description={t('establishment.logo_description')}
        >
            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={pick}
            />
            {cropSrc && (
                <ImageCropper
                    open
                    src={cropSrc}
                    aspect={1}
                    outputWidth={LOGO_SIZE}
                    format="webp"
                    onConfirm={upload}
                    onCancel={closeCropper}
                />
            )}

            <div className="flex flex-wrap items-center gap-5">
                <div className="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed bg-muted">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt={t('establishment.logo_alt')}
                            className="h-full w-full object-contain p-2"
                        />
                    ) : (
                        <Store className="size-9 text-muted-foreground/60" />
                    )}
                </div>

                <div className="space-y-3">
                    <p className="max-w-sm text-sm text-muted-foreground">
                        {t('establishment.logo_hint')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            onClick={() => inputRef.current?.click()}
                            disabled={processing}
                        >
                            <ImagePlus className="h-4 w-4" />
                            {logoUrl
                                ? t('establishment.logo_change')
                                : t('establishment.logo_upload')}
                        </Button>
                        {logoUrl && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={remove}
                                disabled={processing}
                            >
                                <Trash2 className="h-4 w-4" />
                                {t('establishment.logo_remove')}
                            </Button>
                        )}
                    </div>
                    <InputError message={error} />
                </div>
            </div>
        </Section>
    );
}

// ─── Opening hours ────────────────────────────────────────────────────────────

function groupByDay(hours: OpeningHourInterval[]): Interval[][] {
    const days: Interval[][] = Array.from({ length: 7 }, () => []);

    for (const h of hours) {
        days[h.weekday]?.push({ opens_at: h.opens_at, closes_at: h.closes_at });
    }

    return days;
}

/** Flattened weekday 0 → 6, the same order used to read `hours.{i}` errors back. */
function flatten(days: Interval[][]): OpeningHourInterval[] {
    return days.flatMap((intervals, weekday) =>
        intervals.map((i) => ({ weekday, ...i })),
    );
}

function HoursSection({ initial }: { initial: OpeningHourInterval[] }) {
    const { t } = useTranslation();
    const [days, setDays] = useState(() => groupByDay(initial));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const saved = useMemo(() => JSON.stringify(groupByDay(initial)), [initial]);
    const dirty = JSON.stringify(days) !== saved;

    const update = (weekday: number, intervals: Interval[]) =>
        setDays((prev) => prev.map((d, i) => (i === weekday ? intervals : d)));

    const copyToAll = (weekday: number) =>
        setDays((prev) => prev.map(() => prev[weekday].map((i) => ({ ...i }))));

    /** Index of an interval in the flattened payload, to find its errors. */
    const flatIndex = (weekday: number, k: number) =>
        days.slice(0, weekday).reduce((n, d) => n + d.length, 0) + k;

    const save = () => {
        router.put(
            EstablishmentController.updateHours.url(),
            { hours: flatten(days) },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors({});
                },
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Section
            title={t('establishment.hours_title')}
            description={t('establishment.hours_description')}
            aside={
                <Button
                    type="button"
                    onClick={save}
                    disabled={!dirty || processing}
                >
                    {t('establishment.hours_save')}
                </Button>
            }
        >
            <ul className="divide-y divide-dashed">
                {DISPLAY_ORDER.map((weekday) => {
                    const intervals = days[weekday];
                    const open = intervals.length > 0;

                    return (
                        <li
                            key={weekday}
                            className="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start"
                        >
                            <label className="flex w-40 shrink-0 items-center gap-2.5 pt-2 text-sm font-medium">
                                <Checkbox
                                    checked={open}
                                    onCheckedChange={(checked) =>
                                        update(
                                            weekday,
                                            checked
                                                ? [{ ...DEFAULT_INTERVAL }]
                                                : [],
                                        )
                                    }
                                />
                                {t(`establishment.weekday_${weekday}`)}
                            </label>

                            <div className="min-w-0 flex-1 space-y-2">
                                {!open && (
                                    <p className="pt-2 font-receipt text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                        {t('establishment.closed')}
                                    </p>
                                )}

                                {intervals.map((interval, k) => {
                                    const i = flatIndex(weekday, k);
                                    const overnight =
                                        interval.closes_at !== '' &&
                                        interval.closes_at <= interval.opens_at;
                                    const error =
                                        errors[`hours.${i}.opens_at`] ??
                                        errors[`hours.${i}.closes_at`];
                                    const set = (patch: Partial<Interval>) =>
                                        update(
                                            weekday,
                                            intervals.map((x, j) =>
                                                j === k
                                                    ? { ...x, ...patch }
                                                    : x,
                                            ),
                                        );

                                    return (
                                        <div key={k} className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input
                                                    type="time"
                                                    value={interval.opens_at}
                                                    onChange={(e) =>
                                                        set({
                                                            opens_at:
                                                                e.target.value,
                                                        })
                                                    }
                                                    aria-label={t(
                                                        'establishment.opens_at',
                                                    )}
                                                    aria-invalid={!!error}
                                                    className="w-28 tabular-nums"
                                                    required
                                                />
                                                <span className="text-sm text-muted-foreground">
                                                    {t('establishment.until')}
                                                </span>
                                                <Input
                                                    type="time"
                                                    value={interval.closes_at}
                                                    onChange={(e) =>
                                                        set({
                                                            closes_at:
                                                                e.target.value,
                                                        })
                                                    }
                                                    aria-label={t(
                                                        'establishment.closes_at',
                                                    )}
                                                    aria-invalid={!!error}
                                                    className="w-28 tabular-nums"
                                                    required
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        update(
                                                            weekday,
                                                            intervals.filter(
                                                                (_, j) =>
                                                                    j !== k,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'establishment.remove_interval',
                                                    )}
                                                >
                                                    <X className="h-4 w-4" />
                                                </Button>
                                                {overnight && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                        <Moon className="h-3 w-3" />
                                                        {t(
                                                            'establishment.overnight',
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            <InputError message={error} />
                                        </div>
                                    );
                                })}

                                {open && (
                                    <div className="flex flex-wrap gap-1">
                                        {intervals.length <
                                            MAX_INTERVALS_PER_DAY && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    update(weekday, [
                                                        ...intervals,
                                                        { ...DEFAULT_INTERVAL },
                                                    ])
                                                }
                                            >
                                                <Plus className="h-3.5 w-3.5" />
                                                {t(
                                                    'establishment.add_interval',
                                                )}
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => copyToAll(weekday)}
                                        >
                                            <Copy className="h-3.5 w-3.5" />
                                            {t('establishment.copy_to_all')}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <InputError message={errors.hours} className="mt-3" />
        </Section>
    );
}

// ─── Venue photos ─────────────────────────────────────────────────────────────

function PhotosSection({
    photos,
    maxPhotos,
}: {
    photos: VenuePhoto[];
    maxPhotos: number;
}) {
    const { t } = useTranslation();
    const inputRef = useRef<HTMLInputElement>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string>();
    const [toDelete, setToDelete] = useState<VenuePhoto | null>(null);
    const remaining = maxPhotos - photos.length;
    // Picked files wait here and go through the cropper one at a time.
    const [queue, setQueue] = useState<string[]>([]);
    const [queueTotal, setQueueTotal] = useState(0);
    const [cropped, setCropped] = useState<File[]>([]);

    const pick = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        e.target.value = '';

        if (files.length === 0) {
            return;
        }

        if (files.length > remaining) {
            setError(t('establishment.photos_limit', { count: maxPhotos }));

            return;
        }

        const sources = files
            .map((f) => imageSourceUrl(f))
            .filter((src): src is string => src !== null);

        setError(undefined);
        setCropped([]);
        setQueue(sources);
        setQueueTotal(sources.length);
    };

    /** Moves to the next picture; uploads everything once the queue is done. */
    const advance = (files: File[]) => {
        const [current, ...rest] = queue;
        URL.revokeObjectURL(current);
        setQueue(rest);
        setCropped(files);

        if (rest.length === 0 && files.length > 0) {
            upload(files);
        }
    };

    const upload = (files: File[]) => {
        setProcessing(true);

        router.post(
            EstablishmentController.storePhotos.url(),
            { photos: files },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.photos ??
                            Object.entries(errors).find(([k]) =>
                                k.startsWith('photos.'),
                            )?.[1],
                    ),
                onFinish: () => {
                    setProcessing(false);
                    setCropped([]);
                },
            },
        );
    };

    const move = (index: number, delta: -1 | 1) => {
        const ids = photos.map((p) => p.id);
        const target = index + delta;
        [ids[index], ids[target]] = [ids[target], ids[index]];

        router.put(
            EstablishmentController.reorderPhotos.url(),
            { ids },
            { preserveScroll: true },
        );
    };

    const confirmDelete = () => {
        if (!toDelete) {
            return;
        }

        router.delete(
            EstablishmentController.destroyPhoto.url({ photo: toDelete.id }),
            {
                preserveScroll: true,
                onFinish: () => setToDelete(null),
            },
        );
    };

    return (
        <Section
            title={t('establishment.photos_title')}
            description={t('establishment.photos_description')}
            aside={
                <span className="font-receipt text-xs tracking-[0.14em] text-muted-foreground tabular-nums">
                    {photos.length}/{maxPhotos}
                </span>
            }
        >
            <input
                ref={inputRef}
                type="file"
                accept="image/*"
                multiple
                className="hidden"
                onChange={pick}
            />
            {queue.length > 0 && (
                <ImageCropper
                    key={queue[0]}
                    open
                    src={queue[0]}
                    aspect={PHOTO_ASPECT}
                    outputWidth={PHOTO_WIDTH}
                    quality={0.82}
                    description={
                        queueTotal > 1
                            ? t('establishment.photos_crop_progress', {
                                  current: queueTotal - queue.length + 1,
                                  total: queueTotal,
                              })
                            : undefined
                    }
                    onConfirm={(blob) =>
                        advance([
                            ...cropped,
                            croppedFile(blob, `photo-${cropped.length + 1}`),
                        ])
                    }
                    onCancel={() => advance(cropped)}
                />
            )}

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {photos.map((photo, index) => (
                    <figure
                        key={photo.id}
                        className="group relative aspect-[4/3] overflow-hidden rounded-xl border bg-muted"
                    >
                        <img
                            src={photo.url}
                            alt={t('establishment.photo_alt', { n: index + 1 })}
                            loading="lazy"
                            decoding="async"
                            className="h-full w-full object-cover"
                        />
                        {index === 0 && (
                            <span className="absolute top-2 left-2 rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">
                                {t('establishment.photo_cover')}
                            </span>
                        )}
                        <div className="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-gradient-to-t from-black/60 to-transparent p-2 pt-6">
                            <div className="flex gap-1">
                                <PhotoAction
                                    label={t('establishment.photo_move_left')}
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                >
                                    <ArrowLeft className="h-4 w-4" />
                                </PhotoAction>
                                <PhotoAction
                                    label={t('establishment.photo_move_right')}
                                    disabled={index === photos.length - 1}
                                    onClick={() => move(index, 1)}
                                >
                                    <ArrowRight className="h-4 w-4" />
                                </PhotoAction>
                            </div>
                            <PhotoAction
                                label={t('establishment.photo_delete')}
                                onClick={() => setToDelete(photo)}
                            >
                                <Trash2 className="h-4 w-4" />
                            </PhotoAction>
                        </div>
                    </figure>
                ))}

                {remaining > 0 && (
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        disabled={processing}
                        className="flex aspect-[4/3] flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed text-sm font-medium text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-60"
                    >
                        <ImagePlus className="h-6 w-6" />
                        {processing
                            ? t('establishment.photos_uploading')
                            : t('establishment.photos_add')}
                    </button>
                )}
            </div>

            {photos.length === 0 && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {t('establishment.photos_empty')}
                </p>
            )}
            <InputError message={error} className="mt-3" />

            <Dialog
                open={toDelete !== null}
                onOpenChange={(open) => !open && setToDelete(null)}
            >
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>
                            {t('establishment.photo_delete_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('establishment.photo_delete_description')}
                        </DialogDescription>
                    </DialogHeader>
                    {toDelete && (
                        <img
                            src={toDelete.url}
                            alt=""
                            className="aspect-[4/3] w-full rounded-lg object-cover"
                        />
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setToDelete(null)}
                        >
                            {t('establishment.cancel')}
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete}>
                            {t('establishment.photo_delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Section>
    );
}

function PhotoAction({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="flex size-8 items-center justify-center rounded-full bg-white/90 text-neutral-900 shadow-sm transition-opacity hover:bg-white disabled:opacity-35"
        >
            {children}
        </button>
    );
}

Establishment.layout = {
    breadcrumbs: [
        {
            title: 'establishment.breadcrumb',
            href: EstablishmentController.edit.url(),
        },
    ],
};
